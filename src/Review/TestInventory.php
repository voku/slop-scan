<?php

declare(strict_types=1);

namespace SlopScan\Review;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use SlopScan\Fact\PhpFacts;
use SlopScan\Support\ParentNode;

/**
 * Reduces PHPUnit/Pest/Codeception source to deterministic per-test comparison facts.
 *
 * The comparison semantics are an independent port inspired by Heyosseus/sloppy
 * SL503 at b9775b9bf3162f36cc8b3c490660593ca862b821 (MIT), as tracked in #60.
 */
final class TestInventory
{
    private const SKIP_METHODS = ['marktestskipped', 'marktestincomplete'];
    private const PEST_SKIP_METHODS = ['skip', 'todo'];
    private const MOCK_EXPECTATIONS = ['shouldreceive', 'shouldhavereceived', 'shouldnothavereceived'];
    private const CODECEPTION_ASSERTION_PREFIXES = ['see', 'dontsee', 'cansee', 'cantsee'];

    /** @var array<string,int> */
    private array $helpers = [];

    private readonly Standard $printer;

    public function __construct()
    {
        $this->printer = new Standard();
    }

    /** @return null|array<string,TestBody> */
    public static function fromSource(string $text, string $path): ?array
    {
        if (!self::isTestPath($path)) {
            return [];
        }

        $syntax = PhpFacts::parseSyntax($text);
        $statements = $syntax['statements'];
        if ($statements === null) {
            return null;
        }

        return (new self())->collect($statements, self::isCestPath($path));
    }

    public static function isTestPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);

        $lower = strtolower($normalized);

        return str_ends_with($lower, 'cest.php')
            || str_ends_with($lower, 'test.php')
            || preg_match('#(?:^|/)(?:tests?|spec)(?:/|$)#i', $normalized) === 1;
    }

    private static function isCestPath(string $path): bool
    {
        return str_ends_with(strtolower(str_replace('\\', '/', $path)), 'cest.php');
    }

    /** @param list<Stmt> $statements @return array<string,TestBody> */
    private function collect(array $statements, bool $cestFile): array
    {
        $tests = [];
        $finder = new NodeFinder();

        foreach ($finder->findInstanceOf($statements, Stmt\Class_::class) as $class) {
            $className = $class->name?->toString() ?? 'class';
            $cestClass = $cestFile && str_ends_with(strtolower($className), 'cest');
            if ($cestClass && $class->isAbstract()) {
                continue;
            }

            $cestClassBlocked = $cestClass && $this->hasCestBlockedMetadata($class);
            $methods = $class->getMethods();
            $this->helpers = [];

            foreach ($methods as $method) {
                if (!$this->isTestMethod($method, $cestClass) && $method->stmts !== null) {
                    $actorVariables = $cestClass ? $this->cestActorVariables($method) : [];
                    $this->helpers[strtolower($method->name->toString())] = $this->assertionsIn($method->stmts, $actorVariables);
                }
            }

            foreach ($methods as $method) {
                if (!$this->isTestMethod($method, $cestClass)) {
                    continue;
                }

                $name = $className . '::' . $method->name->toString();
                $actorVariables = $cestClass ? $this->cestActorVariables($method) : [];
                $blocked = $cestClassBlocked || ($cestClass && $this->hasCestBlockedMetadata($method));
                $tests[$name] = $this->body($name, $method, $method->stmts ?? [], $blocked, $actorVariables);
            }
        }

        $this->helpers = [];
        foreach ($finder->findInstanceOf($statements, Stmt\Function_::class) as $function) {
            $this->helpers[strtolower($function->name->toString())] = $this->assertionsIn($function->stmts);
        }

        foreach ($finder->findInstanceOf($statements, Expr\FuncCall::class) as $call) {
            $name = $this->pestName($call);
            if ($name === null) {
                continue;
            }

            $argument = $call->args[1] ?? null;
            $closure = $argument instanceof Arg ? $argument->value : null;
            $body = match (true) {
                $closure instanceof Expr\Closure => $closure->stmts,
                $closure instanceof Expr\ArrowFunction => [new Stmt\Expression($closure->expr)],
                default => [],
            };

            $tests[$name] = $this->body($name, $call, $body, $this->pestChainIsSkipped($call));
        }

        ksort($tests, SORT_STRING);

        return $tests;
    }

    private function isTestMethod(Stmt\ClassMethod $method, bool $cestClass = false): bool
    {
        if ($method->stmts === null || !$method->isPublic()) {
            return false;
        }

        if ($cestClass) {
            return !str_starts_with($method->name->toString(), '_');
        }

        if (str_starts_with($method->name->toString(), 'test')) {
            return true;
        }

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (strtolower($attribute->name->getLast()) === 'test') {
                    return true;
                }
            }
        }

        return str_contains($method->getDocComment()?->getText() ?? '', '@test');
    }

    /** @param list<Stmt> $statements @param list<string> $codeceptionActors */
    private function body(
        string $name,
        Node $node,
        array $statements,
        bool $skippedByChain,
        array $codeceptionActors = [],
    ): TestBody {
        return new TestBody(
            name: $name,
            line: max(1, $node->getStartLine()),
            assertions: $this->assertionsIn($statements, $codeceptionActors),
            skipped: $skippedByChain || $this->callsAny($statements, self::SKIP_METHODS),
            trivial: $this->trivialAssertionsIn($statements),
            hash: hash('sha256', $this->printer->prettyPrint($statements)),
        );
    }

    private function pestName(Expr\FuncCall $call): ?string
    {
        $function = $this->functionName($call);
        if (!in_array($function, ['it', 'test'], true)) {
            return null;
        }

        $description = $this->firstStringArgument($call);
        if ($description === null) {
            return null;
        }

        $name = $function === 'it' ? 'it ' . $description : $description;
        $parent = ParentNode::of($call);
        while ($parent !== null) {
            if ($parent instanceof Expr\FuncCall
                && $this->functionName($parent) === 'describe'
                && ($prefix = $this->firstStringArgument($parent)) !== null
            ) {
                $name = $prefix . ' > ' . $name;
            }
            $parent = ParentNode::of($parent);
        }

        return $name;
    }

    private function pestChainIsSkipped(Expr\FuncCall $call): bool
    {
        $cursor = $call;
        while (($parent = ParentNode::of($cursor)) instanceof Expr\MethodCall && $parent->var === $cursor) {
            $name = $this->callName($parent);
            if ($name !== null && in_array(strtolower($name), self::PEST_SKIP_METHODS, true)) {
                return true;
            }
            $cursor = $parent;
        }

        return false;
    }

    /** @param list<Stmt> $statements @param list<string> $codeceptionActors */
    private function assertionsIn(array $statements, array $codeceptionActors = []): int
    {
        $count = 0;
        foreach ($this->calls($statements) as $call) {
            $name = strtolower($this->callName($call) ?? '');
            if ($name === '') {
                continue;
            }

            if ($this->isLocalHelperCall($call) && isset($this->helpers[$name])) {
                $count += $this->helpers[$name];
                continue;
            }

            if (str_starts_with($name, 'assert')
                || str_starts_with($name, 'expectexception')
                || in_array($name, self::MOCK_EXPECTATIONS, true)
                || $this->isPestMatcher($call)
                || $this->isCodeceptionAssertion($call, $codeceptionActors)
            ) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param list<Stmt> $statements */
    private function trivialAssertionsIn(array $statements): int
    {
        $count = 0;
        foreach ($this->calls($statements) as $call) {
            $name = strtolower($this->callName($call) ?? '');
            $arguments = $this->argumentValues($call);

            $trivial = match (true) {
                $name === 'addtoassertioncount' => true,
                $name === 'asserttrue' => $this->isConstant($arguments[0] ?? null, 'true'),
                $name === 'assertfalse' => $this->isConstant($arguments[0] ?? null, 'false'),
                $name === 'assertnull' => $this->isConstant($arguments[0] ?? null, 'null'),
                in_array($name, ['assertsame', 'assertequals'], true) => $this->samePrinted($arguments[0] ?? null, $arguments[1] ?? null),
                default => $this->isTrivialPestMatcher($call, $name, $arguments),
            };

            if ($trivial) {
                ++$count;
            }
        }

        return $count;
    }

    private function hasCestBlockedMetadata(Stmt\Class_|Stmt\ClassMethod $node): bool
    {
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array(strtolower($attribute->name->getLast()), ['skip', 'incomplete'], true)) {
                    return true;
                }
            }
        }

        return preg_match('/@(skip|incomplete)\b/i', $node->getDocComment()?->getText() ?? '') === 1;
    }

    /** @param list<string> $actors */
    private function isCodeceptionAssertion(Node $call, array $actors): bool
    {
        if (!$call instanceof Expr\MethodCall
            || !$call->var instanceof Expr\Variable
            || !is_string($call->var->name)
            || !in_array($call->var->name, $actors, true)
        ) {
            return false;
        }

        $name = strtolower($this->callName($call) ?? '');
        foreach (self::CODECEPTION_ASSERTION_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function cestActorVariables(Stmt\ClassMethod $method): array
    {
        $variables = [];
        foreach ($method->params as $parameter) {
            $name = $parameter->var->name;
            if (!is_string($name)) {
                continue;
            }

            $type = $parameter->type;
            $typeName = $type instanceof Name
                ? $type->getLast()
                : ($type instanceof Node\NullableType && $type->type instanceof Name ? $type->type->getLast() : null);

            if ($name === 'I' || ($typeName !== null && str_ends_with(strtolower($typeName), 'tester'))) {
                $variables[] = $name;
            }
        }

        return $variables;
    }

    private function isPestMatcher(Node $call): bool
    {
        $name = $this->callName($call);

        return $call instanceof Expr\MethodCall
            && $name !== null
            && preg_match('/^to[A-Z]/', $name) === 1
            && $this->expectSubject($call) instanceof Expr;
    }

    /** @param list<Expr> $arguments */
    private function isTrivialPestMatcher(Node $call, string $name, array $arguments): bool
    {
        $subject = $this->expectSubject($call);
        if (!$subject instanceof Expr || (!$subject instanceof Expr\ConstFetch && !$subject instanceof Node\Scalar)) {
            return false;
        }

        return match ($name) {
            'tobetrue' => $this->isConstant($subject, 'true'),
            'tobefalse' => $this->isConstant($subject, 'false'),
            'tobenull' => $this->isConstant($subject, 'null'),
            'tobe', 'toequal' => $this->samePrinted($subject, $arguments[0] ?? null),
            default => false,
        };
    }

    private function expectSubject(Node $call): ?Expr
    {
        if (!$call instanceof Expr\MethodCall) {
            return null;
        }

        $cursor = $call->var;
        while ($cursor instanceof Expr\MethodCall || $cursor instanceof Expr\NullsafeMethodCall) {
            $cursor = $cursor->var;
        }

        if (!$cursor instanceof Expr\FuncCall || $this->functionName($cursor) !== 'expect') {
            return null;
        }

        $argument = $cursor->args[0] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

    private function isLocalHelperCall(Node $call): bool
    {
        if ($call instanceof Expr\FuncCall) {
            return true;
        }

        if ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) {
            return $call->var instanceof Expr\Variable && $call->var->name === 'this';
        }

        return $call instanceof Expr\StaticCall
            && $call->class instanceof Name
            && in_array(strtolower($call->class->toString()), ['self', 'static'], true);
    }

    /** @param list<Stmt> $statements @param list<string> $names */
    private function callsAny(array $statements, array $names): bool
    {
        foreach ($this->calls($statements) as $call) {
            $name = strtolower($this->callName($call) ?? '');
            if (in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Stmt> $statements @return list<Node> */
    private function calls(array $statements): array
    {
        return array_values((new NodeFinder())->find(
            $statements,
            static fn (Node $node): bool => $node instanceof Expr\MethodCall
                || $node instanceof Expr\NullsafeMethodCall
                || $node instanceof Expr\StaticCall
                || $node instanceof Expr\FuncCall,
        ));
    }

    private function callName(Node $call): ?string
    {
        if (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall || $call instanceof Expr\StaticCall)
            && $call->name instanceof Identifier
        ) {
            return $call->name->toString();
        }

        if ($call instanceof Expr\FuncCall && $call->name instanceof Name) {
            return $call->name->getLast();
        }

        return null;
    }

    private function functionName(Expr\FuncCall $call): ?string
    {
        return $call->name instanceof Name ? strtolower($call->name->getLast()) : null;
    }

    private function firstStringArgument(Expr\FuncCall $call): ?string
    {
        $argument = $call->args[0] ?? null;

        return $argument instanceof Arg && $argument->value instanceof Node\Scalar\String_
            ? $argument->value->value
            : null;
    }

    /** @return list<Expr> */
    private function argumentValues(Node $call): array
    {
        if (!$call instanceof Expr\MethodCall
            && !$call instanceof Expr\NullsafeMethodCall
            && !$call instanceof Expr\StaticCall
            && !$call instanceof Expr\FuncCall
        ) {
            return [];
        }

        $values = [];
        foreach ($call->args as $argument) {
            if ($argument instanceof Arg) {
                $values[] = $argument->value;
            }
        }

        return $values;
    }

    private function isConstant(?Expr $expression, string $value): bool
    {
        return $expression instanceof Expr\ConstFetch
            && strtolower($expression->name->toString()) === $value;
    }

    private function samePrinted(?Expr $left, ?Expr $right): bool
    {
        return $left instanceof Expr
            && $right instanceof Expr
            && $this->printer->prettyPrintExpr($left) === $this->printer->prettyPrintExpr($right);
    }

}
