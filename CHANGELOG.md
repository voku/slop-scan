# Changelog

All notable changes to this project will be documented in this file.

## 0.1.7 - 2026-08-13

- Fixed PHAR packaging so runtime dependencies are resolved against PHP 8.3.0, the package's minimum supported PHP line, instead of inheriting the release runner's PHP version.
- This prevents a PHAR built on PHP 8.4 from embedding a dependency tree that refuses to start on supported PHP 8.3 consumers. Source-package dependency constraints remain unchanged.
- The exact candidate passed repository CI, including PHAR build and PHAR startup after resolving the temporary binary dependency tree against PHP 8.3.0.

## 0.1.6 - 2026-08-13

- Fixed baseline delta false positives when an unchanged finding moves only because unrelated lines were inserted earlier in the same file. Exact fingerprints remain primary; unmatched findings are treated as relocations only for an unambiguous semantic 1:1 match on rule, message, path, and evidence.
- Ambiguous duplicate findings remain exact-match-only, and changed evidence still reports normal added/resolved delta entries.
- Added focused regressions for pure line relocation, changed evidence, and ambiguous duplicate relocation. The release candidate passed syntax checks, PHPStan, PHPUnit, self-scan dogfood, and PHAR build/verification.

## 0.1.5 - 2026-08-12

- Added the `compare-slop-scan-delta` portable agent skill, covering both the report-based and checkout-based forms of the `delta` command, the `--fail-on` gate, and the option pairs that are easy to mix up.
- Added the `summarize-slop-scan-stats` portable agent skill for ranking findings by rule and file before reading a large report in full.
- Updated dependencies: `voku/simple-php-code-parser` to `^0.22.2`, `nikic/php-parser` to `^5.8`, `helgesverre/toon` to `^3.2`, `phpstan/phpstan` to `^2.2.8`, `phpunit/phpunit` to `^12.5`, and `infection/infection` to `^0.34.2`. `symfony/console` stays on `^7.4` because Symfony 8 requires PHP 8.4 and this package still supports PHP 8.3.
- Resolved imported PHPDoc types before comparing them with native types, so `use Vendor\Payload as Message;` with `@param Message $message` on a `Payload $message` parameter is no longer reported as a mismatch.
- Extended `php.misleading-phpdoc-types` to interface, trait, and enum members and to `@var` annotations on typed properties, including every property of a grouped `public string $a, $b;` declaration.
- Reported PHPDoc findings at the line of the annotated parameter instead of the enclosing declaration line, so multi-line signatures point at the right place.
- Treated `mixed` unions as equivalent to `mixed`, so `@param mixed|null $value` on a `mixed` parameter is reported as redundant rather than as a disagreement.
- Bumped the `php.structure` fact cache schema version, so existing `.slop-scan.cache.json` files are recomputed instead of replayed against the new PHPDoc fact shape.

## 0.1.4 - 2026-05-18

- Refine excessive suppression counting, so that it's not so noisy. (ignored errors via identifier + comment are ok)

## 0.1.3 - 2026-05-18

- Added release notes for the Markdown low-signal detector ahead of the next cut, including clearer rule guidance for descriptive prose, repository anchors, and checklist-heavy docs.
- Expanded Markdown regression coverage so generic artifact-style docs stay quiet when they contain enough concrete prose or repository-specific commands and file references.
- Added repo-level `scan` defaults for cache files, baseline files, rule filters, path filters, maximum findings, and minimum score so teams can keep common scan settings in JSON config.
- Documented baseline and config-file workflows for those `scan` defaults and added CLI coverage to confirm explicit flags still override configured cache and baseline paths.

## 0.1.2 - 2026-05-04

- Forked the original idea from [`modem-dev/slop-scan`](https://github.com/modem-dev/slop-scan).
- Rewrote the tool in PHP for native CLI usage, Composer packaging, PHAR distribution, and CI workflows.
- Shipped deterministic scan reports with stable finding fingerprints, delta comparison support, compact baselines, and reusable scan caching enabled by default.
- Added built-in PHP heuristics backed by AST parsing and parser-backed PHPDoc analysis, including clone-cluster, placeholder stub/body, type-escape hotspot, misleading PHPDoc, and catch-fallback detection with tuned noise reduction.
- Added JSON, lint, GitHub, TOON, and NDJSON reporters, richer finding metadata, and a `stats` command for repository-level summaries.
- Added configuration and suppression support including ignores, rule overrides, PHPStan-style `ignoreErrors`, and inline `@slop-scan-ignore` directives.
- Added focused docs, PHAR release automation, and fixture plus in-process CLI coverage for self-scan and rule behavior.
