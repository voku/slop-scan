<?php

declare(strict_types=1);

namespace SlopScan;

final class PhpFacts
{
    /** @var null|callable():object */
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
        $functions = [];
        if (!preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(([^)]*)\)\s*\{/m', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches[0] as $index => $match) {
            $offset = $match[1];
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            $name = $matches[1][$index][0];
            $params = array_values(array_filter(array_map(static fn(string $param): string => trim(preg_replace('/=.*$/', '', $param) ?? ''), explode(',', $matches[2][$index][0]))));
            $className = self::enclosingClassName($text, $offset);
            // Qualify methods so common signatures such as constructors do not look duplicated across unrelated classes.
            $signature = ($className !== null ? strtolower($className) . '::' : '') . strtolower($name) . '(' . count($params) . ')';
            $bodyStart = $offset + strlen($match[0]);
            $body = self::balancedBody($text, $bodyStart);
            $functions[] = ['name' => $name, 'signature' => $signature, 'line' => $line, 'body' => $body, 'params' => $params];
        }
        return $functions;
    }

    /** @return list<array{line:int,body:string}> */
    public static function tryCatches(string $text): array
    {
        $catches = [];
        if (!preg_match_all('/catch\s*\([^)]*\)\s*\{/m', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        foreach ($matches[0] as $match) {
            $offset = $match[1];
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            $body = self::balancedBody($text, $offset + strlen($match[0]));
            $catches[] = ['line' => $line, 'body' => $body];
        }
        return $catches;
    }

    /**
     * @param null|callable():object $parserFactory Factory returning an object with parse(), getClasses(), and getFunctions().
     */
    public static function useParserFactoryForTesting(?callable $parserFactory): void
    {
        self::$parserFactory = $parserFactory;
    }

    /** @return array{available:bool,classCount:int,functionCount:int,error?:string} */
    public static function parserSummary(string $absolutePath): array
    {
        $class = '\\voku\\SimplePhpParser\\Parsers\\SimplePhpParser';
        if (self::$parserFactory === null && !class_exists($class)) {
            return ['available' => false, 'classCount' => 0, 'functionCount' => 0];
        }
        try {
            $parser = self::$parserFactory !== null ? (self::$parserFactory)() : new $class();
            $parser->parse($absolutePath);
            return [
                'available' => true,
                'classCount' => count($parser->getClasses()),
                'functionCount' => count($parser->getFunctions()),
            ];
        } catch (\Throwable $exception) {
            return ['available' => true, 'classCount' => 0, 'functionCount' => 0, 'error' => $exception->getMessage()];
        }
    }

    private static function balancedBody(string $text, int $start): string
    {
        $depth = 1;
        $length = strlen($text);
        for ($index = $start; $index < $length; $index++) {
            if ($text[$index] === '{') {
                $depth++;
            } elseif ($text[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $index - $start);
                }
            }
        }
        return substr($text, $start);
    }

    /**
     * Returns the innermost class, interface, or trait scope containing the function at the given byte offset.
     */
    private static function enclosingClassName(string $text, int $offset): ?string
    {
        $position = 0;
        $depth = 0;
        $pendingClass = false;
        $pendingClassName = null;
        $classScopes = [];
        foreach (token_get_all($text) as $token) {
            $content = is_array($token) ? $token[1] : $token;
            if ($position >= $offset) {
                break;
            }
            if (is_array($token)) {
                if ($token[0] === T_CLASS || $token[0] === T_INTERFACE || $token[0] === T_TRAIT) {
                    $pendingClass = true;
                    $pendingClassName = null;
                } elseif ($pendingClass && $token[0] === T_STRING) {
                    $pendingClassName = $content;
                }
            } elseif ($content === '{') {
                $depth++;
                if ($pendingClassName !== null) {
                    $classScopes[] = ['name' => $pendingClassName, 'depth' => $depth];
                    $pendingClass = false;
                    $pendingClassName = null;
                }
            } elseif ($content === '}') {
                if ($classScopes !== [] && $classScopes[array_key_last($classScopes)]['depth'] === $depth) {
                    array_pop($classScopes);
                }
                $depth--;
            }
            $position += strlen($content);
        }
        if ($classScopes === []) {
            return null;
        }
        return $classScopes[array_key_last($classScopes)]['name'];
    }
}
