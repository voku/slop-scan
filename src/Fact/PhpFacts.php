<?php

declare(strict_types=1);

namespace SlopScan\Fact;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard;

final class PhpFacts
{
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

    /** @return list<array{name:string,signature:string,line:int,body:string,params:list<string>}> */
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

    /** @return list<array{line:int,body:string}> */
    public static function tryCatches(string $text): array
    {
        $statements = self::parseStatements($text);
        if ($statements === null) {
            return [];
        }

        return array_map(
            static fn(Stmt\Catch_ $catch): array => [
                'line' => $catch->getStartLine(),
                'body' => self::printStatements($catch->stmts),
            ],
            self::nodeFinder()->findInstanceOf($statements, Stmt\Catch_::class)
        );
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

    /** @return array{name:string,signature:string,line:int,body:string,params:list<string>} */
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
}
