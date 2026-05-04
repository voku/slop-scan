<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;
use voku\SimplePhpParser\Parsers\PhpCodeParser;

final class PhpFacts
{
    private const RECOGNIZED_DEBUG_FUNCTIONS = ['dd', 'print_r', 'ray', 'var_dump'];
    private const GENERIC_EXCEPTION_CLASSES = [
        'exception',
        'errorexception',
        'logicexception',
        'runtimeexception',
        'domainexception',
        'invalidargumentexception',
        'lengthexception',
        'outofrangeexception',
        'overflowexception',
        'rangeexception',
        'underflowexception',
        'unexpectedvalueexception',
    ];

    /** @var null|callable():Parser */
    private static $parserFactory = null;

    /** @return list<array{text:string,line:int}> */
    public static function comments(string $text): array
    {
        $comments = [];
        foreach (token_get_all($text) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $comments[] = ['text' => $token[1], 'line' => $token[2]];
            }
        }
        return $comments;
    }

    /** @return list<array{name:string,signature:string,line:int,body:string,params:list<string>,passThroughCall:null|array{callee:string,args:list<string>}}> */
    public static function functions(string $text): array
    {
        $statements = self::parseStatements($text);
        if ($statements === null) {
            return [];
        }

        $functions = [];
        self::collectFunctions($statements, null, $functions);
        return $functions;
    }

    /**
     * @return list<array{
     *     line:int,
     *     body:string,
     *     statementCount:int,
     *     hasThrow:bool,
     *     hasReturn:bool,
     *     callNames:list<string>,
     *     defaultReturnKinds:list<string>,
     *     thrownExceptions:list<array{class:?string,isGeneric:bool,preservesPrevious:bool,usesCaughtVariable:bool}>
     * }>
     */
    public static function tryCatches(string $text): array
    {
        $statements = self::parseStatements($text);
        if ($statements === null) {
            return [];
        }

        return array_map(
            static fn(Stmt\Catch_ $catch): array => self::catchSummary($catch),
            self::nodeFinder()->findInstanceOf($statements, Stmt\Catch_::class)
        );
    }

    /**
     * @return list<array{
     *     kind:string,
     *     subject:string,
     *     line:int,
     *     params:list<array{name:string,nativeType:?string,phpDocType:?string,phpDocRaw:?string,phpDocExtendedType:?string}>,
     *     return:null|array{nativeType:?string,phpDocType:?string,phpDocRaw:?string,phpDocExtendedType:?string}
     * }>
     */
    public static function phpDocTypeSummaries(string $absolutePath): array
    {
        if (!class_exists(PhpCodeParser::class)) {
            return [];
        }

        try {
            $container = PhpCodeParser::getPhpFiles($absolutePath);
        } catch (\Throwable) {
            return [];
        }

        $entries = [];
        foreach ($container->getFunctionsInfo() as $functionName => $info) {
            $entry = self::phpDocTypeSummaryEntry('function', (string) $functionName, null, $info);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        $classes = $container->getClasses();
        ksort($classes, SORT_STRING);
        foreach ($classes as $className => $class) {
            foreach ($class->getMethodsInfo() as $methodName => $info) {
                $entry = self::phpDocTypeSummaryEntry('method', (string) $methodName, (string) $className, $info);
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        usort(
            $entries,
            static fn(array $left, array $right): int => ($left['line'] <=> $right['line']) ?: strcmp($left['subject'], $right['subject'])
        );

        return $entries;
    }

    /** @return list<array{name:string,line:int}> */
    public static function debugCalls(string $text): array
    {
        $statements = self::parseStatements($text);
        if ($statements === null) {
            return [];
        }

        $calls = [];
        foreach (self::nodeFinder()->findInstanceOf($statements, Expr\FuncCall::class) as $call) {
            if (!$call->name instanceof Name) {
                continue;
            }

            $name = strtolower($call->name->toString());
            if (!in_array($name, self::RECOGNIZED_DEBUG_FUNCTIONS, true)) {
                continue;
            }

            $calls[] = [
                'name' => $name,
                'line' => $call->getStartLine(),
            ];
        }

        return $calls;
    }

    /** @return array{looksLikeTest:bool,testCount:int,mockCount:int,assertionCount:int,expectationCount:int} */
    public static function testCallSummary(string $text, string $path): array
    {
        $statements = self::parseStatements($text);
        if ($statements === null) {
            return [
                'looksLikeTest' => false,
                'testCount' => 0,
                'mockCount' => 0,
                'assertionCount' => 0,
                'expectationCount' => 0,
            ];
        }

        $finder = self::nodeFinder();
        $looksLikeTest = preg_match('#(?:^|/)(?:tests?|spec)(?:/|$)|(?:Test|TestCase)\.php$#i', $path) === 1
            || $finder->findFirst($statements, static fn(Node $node): bool => self::classExtendsTestCase($node)) !== null;

        if (!$looksLikeTest) {
            return [
                'looksLikeTest' => false,
                'testCount' => 0,
                'mockCount' => 0,
                'assertionCount' => 0,
                'expectationCount' => 0,
            ];
        }

        $testMethods = [];
        foreach ($finder->findInstanceOf($statements, Stmt\ClassMethod::class) as $method) {
            if (preg_match('/^test[A-Z0-9_][A-Za-z0-9_]*$/', $method->name->toString()) === 1 || self::hasPhpUnitTestAttribute($method->attrGroups)) {
                $testMethods[$method->getStartLine() . ':' . $method->name->toString()] = true;
            }
        }

        return [
            'looksLikeTest' => true,
            'testCount' => count($testMethods),
            'mockCount' => self::countMockCalls($statements),
            'assertionCount' => self::countAssertions($statements),
            'expectationCount' => self::countExpectations($statements),
        ];
    }

    /**
     * @param null|callable():Parser $parserFactory Factory returning a nikic/php-parser parser instance.
     */
    public static function useParserFactoryForTesting(?callable $parserFactory): void
    {
        self::$parserFactory = $parserFactory;
    }

    /** @return array{available:bool,classCount:int,functionCount:int,error?:string} */
    public static function parserSummary(string $absolutePath): array
    {
        if (self::$parserFactory === null && !class_exists(ParserFactory::class)) {
            return ['available' => false, 'classCount' => 0, 'functionCount' => 0];
        }

        $text = file_get_contents($absolutePath);
        if ($text === false) {
            return ['available' => true, 'classCount' => 0, 'functionCount' => 0, 'error' => 'Unable to read file'];
        }

        try {
            $statements = self::parser()->parse($text) ?? [];
            $finder = self::nodeFinder();
            $classCount = count($finder->find($statements, static fn(Node $node): bool => $node instanceof Stmt\Class_ || $node instanceof Stmt\Interface_ || $node instanceof Stmt\Trait_ || $node instanceof Stmt\Enum_));
            $functionCount = count($finder->findInstanceOf($statements, Stmt\Function_::class));

            return [
                'available' => true,
                'classCount' => $classCount,
                'functionCount' => $functionCount,
            ];
        } catch (\Throwable $exception) {
            return ['available' => true, 'classCount' => 0, 'functionCount' => 0, 'error' => $exception->getMessage()];
        }
    }

    /** @param list<Stmt> $statements */
    private static function collectFunctions(array $statements, ?string $className, array &$functions): void
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Function_) {
                $functions[] = self::functionSummary($statement, null);
                continue;
            }

            if ($statement instanceof Stmt\ClassLike) {
                $nestedClassName = $statement->name instanceof Node\Identifier ? $statement->name->toString() : null;
                foreach ($statement->getMethods() as $method) {
                    if ($method->stmts === null) {
                        continue;
                    }
                    $functions[] = self::functionSummary($method, $nestedClassName);
                }

                foreach (self::childStatements($statement) as $childStatements) {
                    self::collectFunctions($childStatements, $nestedClassName ?? $className, $functions);
                }
                continue;
            }

            foreach (self::childStatements($statement) as $childStatements) {
                self::collectFunctions($childStatements, $className, $functions);
            }
        }
    }

    /** @return array{name:string,signature:string,line:int,body:string,params:list<string>,passThroughCall:null|array{callee:string,args:list<string>}} */
    private static function functionSummary(Stmt\ClassMethod|Stmt\Function_ $function, ?string $className): array
    {
        $name = $function->name->toString();
        $params = array_map(
            static fn(Node\Param $param): string => '$' . ((string) $param->var->name),
            $function->params
        );
        $signature = ($className !== null ? strtolower($className) . '::' : '') . strtolower($name) . '(' . count($params) . ')';

        return [
            'name' => $name,
            'signature' => $signature,
            'line' => $function->getStartLine(),
            'body' => self::printStatements($function->stmts ?? []),
            'params' => $params,
            'passThroughCall' => self::passThroughCallSummary($function, $params),
        ];
    }

    /** @return list<Stmt> */
    private static function parseStatements(string $text): ?array
    {
        try {
            return self::parser()->parse($text) ?? [];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function parser(): Parser
    {
        return self::$parserFactory !== null
            ? (self::$parserFactory)()
            : (new ParserFactory())->createForHostVersion();
    }

    private static function nodeFinder(): NodeFinder
    {
        return new NodeFinder();
    }

    /** @param list<Stmt> $statements */
    private static function printStatements(array $statements): string
    {
        if ($statements === []) {
            return '';
        }

        return trim((new Standard())->prettyPrint($statements));
    }

    /**
     * @return list<list<Stmt>>
     */
    private static function childStatements(Node $node): array
    {
        $children = [];

        foreach ($node->getSubNodeNames() as $name) {
            $value = $node->$name;
            if (is_array($value) && $value !== [] && self::isStatementList($value)) {
                $children[] = $value;
            }
        }

        return $children;
    }

    private static function isStatementList(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (!$node instanceof Stmt) {
                return false;
            }
        }

        return true;
    }

    private static function classExtendsTestCase(Node $node): bool
    {
        if (!$node instanceof Stmt\Class_ || !$node->extends instanceof Name) {
            return false;
        }

        $name = ltrim(strtolower($node->extends->toString()), '\\');

        return $name === 'phpunit\\framework\\testcase' || str_ends_with($name, '\\testcase') || $name === 'testcase';
    }

    /** @param list<Node\AttributeGroup> $attributeGroups */
    private static function hasPhpUnitTestAttribute(array $attributeGroups): bool
    {
        foreach ($attributeGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::isPhpUnitTestAttribute($attribute)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isPhpUnitTestAttribute(Attribute $attribute): bool
    {
        $name = ltrim(strtolower($attribute->name->toString()), '\\');

        return $name === 'test' || $name === 'phpunit\\framework\\attributes\\test' || str_ends_with($name, '\\test');
    }

    /** @param list<Stmt> $statements */
    private static function countMockCalls(array $statements): int
    {
        $count = 0;
        foreach (self::methodAndStaticCalls($statements) as $node) {
            $name = self::callIdentifier($node);
            if ($name === null) {
                continue;
            }

            if (in_array($name, ['createmock', 'createconfiguredmock', 'getmockbuilder', 'prophesize'], true)) {
                $count++;
                continue;
            }

            if ($node instanceof Expr\StaticCall && $name === 'mock' && $node->class instanceof Name && strtolower(ltrim($node->class->toString(), '\\')) === 'mockery') {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<Stmt> $statements */
    private static function countAssertions(array $statements): int
    {
        $count = 0;
        foreach (self::methodAndStaticCalls($statements) as $node) {
            $name = self::callIdentifier($node);
            if ($name === null) {
                continue;
            }

            if ($node->name instanceof Identifier && preg_match('/^assert[A-Z]/', $node->name->toString()) === 1 && self::isPhpUnitAssertionReceiver($node)) {
                $count++;
                continue;
            }

            if (in_array($name, ['expectexception', 'expectexceptioncode', 'expectexceptionmessage', 'expectexceptionmessagematches', 'expectexceptionobject', 'expectnottoperformassertions'], true) && self::isPhpUnitAssertionReceiver($node)) {
                $count++;
            }
        }

        return $count;
    }

    /** @param list<Stmt> $statements @return list<Expr\MethodCall|Expr\StaticCall> */
    private static function methodAndStaticCalls(array $statements): array
    {
        return array_merge(
            self::nodeFinder()->findInstanceOf($statements, Expr\MethodCall::class),
            self::nodeFinder()->findInstanceOf($statements, Expr\StaticCall::class),
        );
    }

    /** @param list<Stmt> $statements */
    private static function countExpectations(array $statements): int
    {
        $count = 0;
        foreach (self::nodeFinder()->findInstanceOf($statements, Expr\MethodCall::class) as $call) {
            $name = self::callIdentifier($call);
            if ($name !== null && in_array($name, ['expects', 'shouldreceive', 'shouldhavereceived'], true)) {
                $count++;
            }
        }

        return $count;
    }

    private static function isPhpUnitAssertionReceiver(Expr\MethodCall|Expr\StaticCall $call): bool
    {
        if ($call instanceof Expr\MethodCall) {
            return $call->var instanceof Expr\Variable && $call->var->name === 'this';
        }

        if (!$call->class instanceof Name) {
            return false;
        }

        $class = strtolower($call->class->toString());

        return $class === 'self' || $class === 'static';
    }

    private static function callIdentifier(Expr\MethodCall|Expr\StaticCall $call): ?string
    {
        if (!$call->name instanceof Identifier) {
            return null;
        }

        return strtolower($call->name->toString());
    }

    /**
     * @param array<string,mixed> $info
     * @return null|array{
     *     kind:string,
     *     subject:string,
     *     line:int,
     *     params:list<array{name:string,nativeType:?string,phpDocType:?string,phpDocRaw:?string,phpDocExtendedType:?string}>,
     *     return:null|array{nativeType:?string,phpDocType:?string,phpDocRaw:?string,phpDocExtendedType:?string}
     * }
     */
    private static function phpDocTypeSummaryEntry(string $kind, string $memberName, ?string $className, array $info): ?array
    {
        $params = [];
        foreach (($info['paramsTypes'] ?? []) as $paramName => $types) {
            $raw = $info['paramsPhpDocRaw'][$paramName] ?? null;
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }

            $params[] = [
                'name' => (string) $paramName,
                'nativeType' => is_string($types['type'] ?? null) ? $types['type'] : null,
                'phpDocType' => is_string($types['typeFromPhpDoc'] ?? null) ? $types['typeFromPhpDoc'] : null,
                'phpDocRaw' => $raw,
                'phpDocExtendedType' => is_string($types['typeFromPhpDocExtended'] ?? null) ? $types['typeFromPhpDocExtended'] : null,
            ];
        }

        $returnRaw = $info['returnPhpDocRaw'] ?? null;
        $return = is_string($returnRaw) && trim($returnRaw) !== ''
            ? [
                'nativeType' => is_string($info['returnTypes']['type'] ?? null) ? $info['returnTypes']['type'] : null,
                'phpDocType' => is_string($info['returnTypes']['typeFromPhpDoc'] ?? null) ? $info['returnTypes']['typeFromPhpDoc'] : null,
                'phpDocRaw' => $returnRaw,
                'phpDocExtendedType' => is_string($info['returnTypes']['typeFromPhpDocExtended'] ?? null) ? $info['returnTypes']['typeFromPhpDocExtended'] : null,
            ]
            : null;

        if ($params === [] && $return === null) {
            return null;
        }

        $subject = $className !== null ? $className . '::' . $memberName : $memberName;

        return [
            'kind' => $kind,
            'subject' => $subject,
            'line' => is_int($info['line'] ?? null) ? $info['line'] : 1,
            'params' => $params,
            'return' => $return,
        ];
    }

    /** @param list<string> $params @return null|array{callee:string,args:list<string>} */
    private static function passThroughCallSummary(Stmt\ClassMethod|Stmt\Function_ $function, array $params): ?array
    {
        if ($params === [] || ($function->stmts ?? []) === [] || count($function->stmts ?? []) !== 1) {
            return null;
        }

        $statement = $function->stmts[0] ?? null;
        if (!$statement instanceof Stmt\Return_ || !$statement->expr instanceof Expr\FuncCall) {
            return null;
        }

        if (!$statement->expr->name instanceof Node\Name) {
            return null;
        }

        $args = [];
        foreach ($statement->expr->getArgs() as $arg) {
            $argName = self::variableArgumentName($arg);
            if ($argName === null) {
                return null;
            }

            $args[] = $argName;
        }

        if ($args !== $params) {
            return null;
        }

        return [
            'callee' => $statement->expr->name->toString(),
            'args' => $args,
        ];
    }

    private static function variableArgumentName(Arg $arg): ?string
    {
        if ($arg->name !== null || $arg->unpack || !$arg->value instanceof Expr\Variable || !is_string($arg->value->name)) {
            return null;
        }

        return '$' . $arg->value->name;
    }

    /**
     * @return array{
     *     line:int,
     *     body:string,
     *     statementCount:int,
     *     hasThrow:bool,
     *     hasReturn:bool,
     *     callNames:list<string>,
     *     defaultReturnKinds:list<string>,
     *     thrownExceptions:list<array{class:?string,isGeneric:bool,preservesPrevious:bool,usesCaughtVariable:bool}>
     * }
     */
    private static function catchSummary(Stmt\Catch_ $catch): array
    {
        $finder = self::nodeFinder();
        $catchVariableName = is_string($catch->var?->name) ? $catch->var->name : null;
        $callNames = [];
        foreach ($finder->find($catch->stmts, static function (Node $node): bool {
            return $node instanceof Expr\FuncCall || $node instanceof Expr\Print_ || $node instanceof Stmt\Echo_;
        }) as $node) {
            if ($node instanceof Expr\FuncCall && $node->name instanceof Node\Name) {
                $callNames[] = strtolower($node->name->toString());
                continue;
            }

            if ($node instanceof Expr\Print_) {
                $callNames[] = 'print';
                continue;
            }

            if ($node instanceof Stmt\Echo_) {
                $callNames[] = 'echo';
            }
        }

        $callNames = array_values(array_unique($callNames));
        sort($callNames, SORT_STRING);

        $defaultReturnKinds = [];
        foreach ($finder->findInstanceOf($catch->stmts, Stmt\Return_::class) as $return) {
            $kind = self::defaultLiteralKind($return->expr);
            if ($kind === null) {
                continue;
            }

            $defaultReturnKinds[] = $kind;
        }

        $defaultReturnKinds = array_values(array_unique($defaultReturnKinds));
        sort($defaultReturnKinds, SORT_STRING);

        $thrownExceptions = [];
        foreach ($finder->findInstanceOf($catch->stmts, Expr\Throw_::class) as $throw) {
            $thrownExceptions[] = self::thrownExceptionSummary($throw, $catchVariableName);
        }

        return [
            'line' => $catch->getStartLine(),
            'body' => self::printStatements($catch->stmts),
            'statementCount' => count($catch->stmts),
            'hasThrow' => $finder->findFirst($catch->stmts, static fn(Node $node): bool => $node instanceof Expr\Throw_) !== null,
            'hasReturn' => $finder->findFirstInstanceOf($catch->stmts, Stmt\Return_::class) !== null,
            'callNames' => $callNames,
            'defaultReturnKinds' => $defaultReturnKinds,
            'thrownExceptions' => $thrownExceptions,
        ];
    }

    private static function defaultLiteralKind(?Expr $expr): ?string
    {
        if ($expr instanceof Expr\ConstFetch) {
            $name = strtolower($expr->name->toString());

            return match ($name) {
                'null' => 'null',
                'false' => 'false',
                default => null,
            };
        }

        if (($expr instanceof Node\Scalar\LNumber || $expr instanceof Node\Scalar\DNumber) && $expr->value == 0.0) {
            return 'zero';
        }

        if ($expr instanceof Node\Scalar\String_ && $expr->value === '') {
            return 'empty-string';
        }

        if ($expr instanceof Expr\Array_ && $expr->items === []) {
            return 'empty-array';
        }

        return null;
    }

    /**
     * @return array{class:?string,isGeneric:bool,preservesPrevious:bool,usesCaughtVariable:bool}
     */
    private static function thrownExceptionSummary(Expr\Throw_ $throw, ?string $catchVariableName): array
    {
        if (!$throw->expr instanceof Expr\New_) {
            return [
                'class' => null,
                'isGeneric' => false,
                'preservesPrevious' => false,
                'usesCaughtVariable' => false,
            ];
        }

        $class = $throw->expr->class instanceof Name
            ? ltrim($throw->expr->class->toString(), '\\')
            : null;
        $usesCaughtVariable = $catchVariableName !== null && self::callUsesVariable($throw->expr->getArgs(), $catchVariableName);

        return [
            'class' => $class,
            'isGeneric' => self::isGenericExceptionClass($class),
            'preservesPrevious' => $catchVariableName !== null && self::newExceptionPreservesPrevious($throw->expr, $catchVariableName),
            'usesCaughtVariable' => $usesCaughtVariable,
        ];
    }

    /**
     * @param list<Arg> $args
     */
    private static function callUsesVariable(array $args, string $variableName): bool
    {
        foreach ($args as $arg) {
            if (self::expressionUsesVariable($arg->value, $variableName)) {
                return true;
            }
        }

        return false;
    }

    private static function expressionUsesVariable(Expr $expr, string $variableName): bool
    {
        return self::nodeFinder()->findFirst(
            [$expr],
            static fn(Node $node): bool => $node instanceof Expr\Variable && $node->name === $variableName
        ) !== null;
    }

    private static function newExceptionPreservesPrevious(Expr\New_ $new, string $catchVariableName): bool
    {
        foreach ($new->getArgs() as $index => $arg) {
            if (!self::expressionUsesVariable($arg->value, $catchVariableName)) {
                continue;
            }

            $argumentName = strtolower($arg->name?->toString() ?? '');
            if ($argumentName === 'previous' || ($arg->name === null && $index === 2)) {
                return true;
            }
        }

        return false;
    }

    private static function isGenericExceptionClass(?string $class): bool
    {
        if ($class === null) {
            return false;
        }

        return in_array(strtolower($class), self::GENERIC_EXCEPTION_CLASSES, true);
    }
}
