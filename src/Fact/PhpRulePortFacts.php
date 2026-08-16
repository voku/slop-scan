<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * AST facts used only by PHP adaptations of upstream rules.
 *
 * @phpstan-type GenericArrayCast array{variable:string,kind:string,line:int,column:int}
 * @phpstan-type CaughtExceptionNormalization array{kind:string,line:int,column:int}
 * @phpstan-type StatusEnvelope array{statusKey:string,statusValue:string,payloadKeys:list<string>,kind:string,line:int,column:int}
 * @phpstan-type Summary array{
 *     genericArrayCasts:list<GenericArrayCast>,
 *     caughtExceptionNormalizations:list<CaughtExceptionNormalization>,
 *     statusEnvelopes:list<StatusEnvelope>
 * }
 */
final class PhpRulePortFacts
{
    private const GENERIC_BAG_VARIABLES = [
        'body',
        'config',
        'data',
        'json',
        'obj',
        'parsed',
        'payload',
        'record',
        'result',
        'value',
    ];

    private const GENERIC_ERROR_VARIABLES = [
        'detail',
        'details',
        'error',
        'message',
        'reason',
    ];

    private const STATUS_ENVELOPE_STATUS_KEYS = ['ok', 'status', 'success'];
    private const STATUS_ENVELOPE_PAYLOAD_KEYS = [
        'data',
        'detail',
        'details',
        'error',
        'errors',
        'info',
        'message',
        'payload',
        'reason',
        'result',
        'results',
        'rows',
    ];

    /** @return Summary */
    public static function summarize(string $text): array
    {
        $statements = self::parseStatementsWithParents($text);
        if ($statements === null) {
            return [
                'genericArrayCasts' => [],
                'caughtExceptionNormalizations' => [],
                'statusEnvelopes' => [],
            ];
        }

        $finder = new NodeFinder();
        $genericArrayCasts = [];
        foreach ($finder->findInstanceOf($statements, Expr\Assign::class) as $assign) {
            $match = self::genericArrayCast($assign, $text);
            if ($match !== null) {
                $genericArrayCasts[] = $match;
            }
        }

        $caughtExceptionNormalizations = [];
        foreach ($finder->findInstanceOf($statements, Stmt\Catch_::class) as $catch) {
            foreach (self::catchNormalizations($catch, $text, $finder) as $match) {
                $caughtExceptionNormalizations[] = $match;
            }
        }

        $statusEnvelopes = [];
        foreach ($finder->findInstanceOf($statements, Expr\Array_::class) as $array) {
            $envelope = self::statusEnvelope($array, $text);
            if ($envelope !== null) {
                $statusEnvelopes[] = $envelope;
            }
        }

        usort(
            $genericArrayCasts,
            static fn (array $left, array $right): int => ($left['line'] <=> $right['line'])
                ?: ($left['column'] <=> $right['column'])
                ?: strcmp($left['variable'], $right['variable']),
        );
        usort(
            $caughtExceptionNormalizations,
            static fn (array $left, array $right): int => ($left['line'] <=> $right['line'])
                ?: ($left['column'] <=> $right['column'])
                ?: strcmp($left['kind'], $right['kind']),
        );
        usort(
            $statusEnvelopes,
            static fn (array $left, array $right): int => ($left['line'] <=> $right['line'])
                ?: ($left['column'] <=> $right['column'])
                ?: strcmp($left['statusKey'], $right['statusKey']),
        );

        return [
            'genericArrayCasts' => $genericArrayCasts,
            'caughtExceptionNormalizations' => self::uniqueNormalizations($caughtExceptionNormalizations),
            'statusEnvelopes' => $statusEnvelopes,
        ];
    }

    /** @return null|GenericArrayCast */
    private static function genericArrayCast(Expr\Assign $assign, string $text): ?array
    {
        if (!$assign->var instanceof Expr\Variable || !is_string($assign->var->name)) {
            return null;
        }

        $variable = strtolower($assign->var->name);
        if (!in_array($variable, self::GENERIC_BAG_VARIABLES, true)) {
            return null;
        }

        $kind = self::genericArrayCastKind($assign->expr);
        if ($kind === null) {
            return null;
        }

        return [
            'variable' => '$' . $assign->var->name,
            'kind' => $kind,
            'line' => $assign->expr->getStartLine(),
            'column' => self::nodeStartColumn($assign->expr, $text),
        ];
    }

    private static function genericArrayCastKind(Expr $expr): ?string
    {
        if ($expr instanceof Expr\Cast\Array_) {
            return 'array-cast';
        }

        if (!$expr instanceof Expr\FuncCall
            || !$expr->name instanceof Name
            || strtolower(ltrim($expr->name->toString(), '\\')) !== 'json_decode'
        ) {
            return null;
        }

        foreach ($expr->getArgs() as $index => $arg) {
            $argumentName = strtolower($arg->name?->toString() ?? '');
            if ($argumentName === 'associative') {
                return self::isTrueLiteral($arg->value) ? 'json-decode-assoc' : null;
            }

            if ($arg->name === null && $index === 1) {
                return self::isTrueLiteral($arg->value) ? 'json-decode-assoc' : null;
            }
        }

        return null;
    }

    private static function isTrueLiteral(Node $node): bool
    {
        return $node instanceof Expr\ConstFetch
            && strtolower($node->name->toString()) === 'true';
    }

    /** @return null|StatusEnvelope */
    private static function statusEnvelope(Expr\Array_ $array, string $text): ?array
    {
        $statusKey = null;
        $statusValue = null;
        $payloadKeys = [];

        foreach ($array->items as $item) {
            if (!$item->key instanceof Node\Scalar\String_) {
                continue;
            }

            $key = strtolower($item->key->value);
            $booleanValue = self::booleanLiteralName($item->value);
            if ($statusKey === null && $booleanValue !== null && in_array($key, self::STATUS_ENVELOPE_STATUS_KEYS, true)) {
                $statusKey = $key;
                $statusValue = $booleanValue;
                continue;
            }

            if (in_array($key, self::STATUS_ENVELOPE_PAYLOAD_KEYS, true)) {
                $payloadKeys[$key] = true;
            }
        }

        if ($statusKey === null || $statusValue === null || $payloadKeys === []) {
            return null;
        }

        $keys = array_keys($payloadKeys);
        sort($keys);

        return [
            'statusKey' => $statusKey,
            'statusValue' => $statusValue,
            'payloadKeys' => $keys,
            'kind' => self::statusEnvelopeContextKind($array),
            'line' => $array->getStartLine(),
            'column' => self::nodeStartColumn($array, $text),
        ];
    }

    private static function booleanLiteralName(Node $node): ?string
    {
        if (!$node instanceof Expr\ConstFetch) {
            return null;
        }

        $name = strtolower($node->name->toString());

        return in_array($name, ['true', 'false'], true) ? $name : null;
    }

    private static function statusEnvelopeContextKind(Expr\Array_ $array): string
    {
        $parent = self::parent($array);
        if ($parent instanceof Stmt\Return_ && $parent->expr === $array) {
            return 'returned-generic-status-envelope';
        }

        if (!$parent instanceof Arg || $parent->value !== $array) {
            return 'assigned-generic-status-envelope';
        }

        $call = self::parent($parent);
        if ($call instanceof Expr\MethodCall
            && $call->name instanceof Identifier
            && strtolower($call->name->toString()) === 'json'
        ) {
            return 'json-generic-status-envelope';
        }

        if ($call instanceof Expr\New_
            && $call->class instanceof Name
            && str_ends_with(strtolower($call->class->toString()), 'jsonresponse')
        ) {
            return 'json-generic-status-envelope';
        }

        return 'assigned-generic-status-envelope';
    }

    /** @return list<CaughtExceptionNormalization> */
    private static function catchNormalizations(Stmt\Catch_ $catch, string $text, NodeFinder $finder): array
    {
        $catchVariable = is_string($catch->var?->name) ? $catch->var->name : null;
        if ($catchVariable === null) {
            return [];
        }

        $matches = [];
        $nodes = $finder->find(
            $catch->stmts,
            static fn (Node $node): bool => $node instanceof Stmt\Return_
                || $node instanceof Expr\Assign
                || $node instanceof Node\ArrayItem,
        );

        foreach ($nodes as $node) {
            if (!self::belongsToCatch($node, $catch)) {
                continue;
            }

            if ($node instanceof Stmt\Return_) {
                $kind = self::caughtValueKind($node->expr, $catchVariable, $catch);
                if ($kind !== null && $node->expr !== null) {
                    $matches[] = self::normalization('returned-' . $kind, $node->expr, $text);
                }
                continue;
            }

            if ($node instanceof Expr\Assign
                && $node->var instanceof Expr\Variable
                && is_string($node->var->name)
                && in_array(strtolower($node->var->name), self::GENERIC_ERROR_VARIABLES, true)
            ) {
                $kind = self::caughtValueKind($node->expr, $catchVariable, $catch);
                if ($kind !== null) {
                    $matches[] = self::normalization('assigned-' . $kind, $node->expr, $text);
                }
                continue;
            }

            if ($node instanceof Node\ArrayItem
                && $node->key instanceof Node\Scalar\String_
                && in_array(strtolower($node->key->value), self::GENERIC_ERROR_VARIABLES, true)
            ) {
                $kind = self::caughtValueKind($node->value, $catchVariable, $catch);
                if ($kind !== null) {
                    $matches[] = self::normalization('property-' . $kind, $node->value, $text);
                }
            }
        }

        return $matches;
    }

    private static function belongsToCatch(Node $node, Stmt\Catch_ $catch): bool
    {
        $parent = self::parent($node);
        while ($parent !== null) {
            if ($parent instanceof Stmt\Catch_) {
                return $parent === $catch;
            }
            $parent = self::parent($parent);
        }

        return false;
    }

    private static function caughtValueKind(?Expr $expr, string $catchVariable, Stmt\Catch_ $catch): ?string
    {
        if ($expr instanceof Expr\MethodCall
            && $expr->var instanceof Expr\Variable
            && $expr->var->name === $catchVariable
            && self::isCatchVariableReference($expr->var, $catchVariable, $catch)
            && $expr->name instanceof Identifier
            && strtolower($expr->name->toString()) === 'getmessage'
            && $expr->getArgs() === []
        ) {
            return 'caught-message';
        }

        if ($expr instanceof Expr\Cast\String_
            && self::expressionUsesCatchVariable($expr->expr, $catchVariable, $catch)
        ) {
            return 'caught-string';
        }

        return null;
    }

    private static function expressionUsesCatchVariable(Expr $expr, string $variable, Stmt\Catch_ $catch): bool
    {
        return (new NodeFinder())->findFirst(
            [$expr],
            static fn (Node $node): bool => $node instanceof Expr\Variable
                && $node->name === $variable
                && self::isCatchVariableReference($node, $variable, $catch),
        ) !== null;
    }

    private static function isCatchVariableReference(
        Expr\Variable $variableNode,
        string $variable,
        Stmt\Catch_ $catch,
    ): bool {
        $parent = self::parent($variableNode);
        while ($parent !== null) {
            if ($parent === $catch) {
                return true;
            }

            if ($parent instanceof Stmt\Catch_) {
                if (is_string($parent->var?->name) && $parent->var->name === $variable) {
                    return false;
                }
            } elseif ($parent instanceof Expr\Closure) {
                if (self::hasParameterNamed($parent->params, $variable)) {
                    return false;
                }
                if (!self::closureUsesVariable($parent, $variable)) {
                    return false;
                }
            } elseif ($parent instanceof Expr\ArrowFunction) {
                if (self::hasParameterNamed($parent->params, $variable)) {
                    return false;
                }
            } elseif ($parent instanceof Stmt\Function_ || $parent instanceof Stmt\ClassMethod) {
                return false;
            }

            $parent = self::parent($parent);
        }

        return false;
    }

    /** @param list<Node\Param> $params */
    private static function hasParameterNamed(array $params, string $variable): bool
    {
        foreach ($params as $param) {
            if ($param->var->name === $variable) {
                return true;
            }
        }

        return false;
    }

    private static function closureUsesVariable(Expr\Closure $closure, string $variable): bool
    {
        foreach ($closure->uses as $use) {
            if ($use->var->name === $variable) {
                return true;
            }
        }

        return false;
    }

    /** @return CaughtExceptionNormalization */
    private static function normalization(string $kind, Expr $expr, string $text): array
    {
        return [
            'kind' => $kind,
            'line' => $expr->getStartLine(),
            'column' => self::nodeStartColumn($expr, $text),
        ];
    }

    /**
     * @param list<CaughtExceptionNormalization> $matches
     * @return list<CaughtExceptionNormalization>
     */
    private static function uniqueNormalizations(array $matches): array
    {
        $unique = [];
        foreach ($matches as $match) {
            $key = $match['line'] . ':' . $match['column'] . ':' . $match['kind'];
            $unique[$key] = $match;
        }

        return array_values($unique);
    }

    /** @return null|list<Stmt> */
    private static function parseStatementsWithParents(string $text): ?array
    {
        try {
            $statements = self::parser()->parse($text) ?? [];
            $withParents = (new NodeTraverser(new ParentConnectingVisitor()))->traverse($statements);
            /** @var list<Stmt> $withParents */
            return $withParents;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function parser(): Parser
    {
        return (new ParserFactory())->createForHostVersion();
    }

    private static function parent(Node $node): ?Node
    {
        $parent = $node->getAttribute('parent');

        return $parent instanceof Node ? $parent : null;
    }

    private static function nodeStartColumn(Node $node, string $text): int
    {
        $start = $node->getStartFilePos();
        if ($start < 0) {
            return 1;
        }

        $prefix = substr($text, 0, $start);
        $lineStart = strrpos($prefix, "\n");

        return $lineStart === false ? $start + 1 : $start - $lineStart;
    }
}
