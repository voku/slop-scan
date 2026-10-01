# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Fixed an out-of-memory crash (and a fatal when a scanned parent class could not be autoloaded) while extracting PHPDoc facts: the vendored parser defaulted to reflection enrichment, which autoloaded every scanned class-like into the scanner process and recursed without bound on self-referencing or aliased parents such as php-parser's `if (false) { class ArrayItem extends \\PhpParser\\Node\\ArrayItem }` shims. Scans now use `ParserOptions::astOnly()`, so PHPDoc facts depend only on the scanned source, never on the scanner's own autoloader. Requires `voku/simple-php-code-parser` ^0.22.5.
- Bumped the `php.structure` cache schema and baseline rule-semantics compatibility because PHPDoc facts no longer include reflection-derived inherited data.

- Extended `php.placeholder-method-bodies` with explicit unfinished-implementation evidence (issue #59): not-implemented-only exception bodies, short elision comments inside a method, and a leading `TODO`/`FIXME`/`XXX` comment on an empty or constant-return body. Concrete-reason exceptions, interface/abstract/trait methods, test files and test doubles, and marker words inside explanatory prose stay quiet. Idea inspired by Heyosseus/sloppy `SL112` (MIT); no code was copied.
- Bumped the `php.structure` cache schema and baseline rule-semantics compatibility because the function summaries gained facts and the rule's finding surface widened.

- Refined `php.placeholder-comments` so prose that explains an already resolved/removed/deleted TODO no longer reads as deferred work; comments that open with `TODO`/`FIXME`/`HACK`/`XXX` still report, even when they mention removal.
- Bumped baseline rule-semantics compatibility because the existing rule ID now has a narrower finding surface.

## 0.1.12 - 2026-09-29

- Refined `php.generic-array-casts` for three consumer-proven deserialization boundaries: an intermediate schema-key projection before a terminating shape guard, a two-phase throwing decode with strict scalar status validation, and a strong immediate non-throwing identity/shape guard. Weak transport bags and directly returned decoded arrays remain findings.
- Bumped baseline rule-semantics compatibility because the existing rule ID now has a narrower finding surface.

## 0.1.11 - 2026-09-28

- Refined `php.catch-returns-exception-message` so deliberate structured failure/diagnostic records with non-generic context keys no longer look like exception text flattened into ordinary payload data; bare generic `{error,message}` payloads and direct returns remain findings.
- Bumped baseline rule-semantics compatibility because the existing rule ID now has a narrower finding surface.

## 0.1.10 - 2026-09-27

- Refined `php.generic-array-casts` so a short-lived associative JSON bag stays quiet when `JSON_THROW_ON_ERROR` is followed immediately by a terminating structural guard with `!is_array()` and literal-key validation; unchecked, late-validated, non-throwing, and `(array)` conversions still report.
- Bumped the baseline rule-semantics compatibility version because this intentionally changes the finding surface of an existing rule ID.

## 0.1.9 - 2026-09-27

- Fail baseline-aware scans closed when the active rule surface or explicit rule-semantics compatibility version differs from the baseline, instead of misclassifying scanner-introduced findings as candidate additions.
- Persist baseline compatibility metadata and require legacy baselines without it to be reviewed and regenerated explicitly; generic report-to-report `delta` remains unchanged.

## 0.1.8 - 2026-09-27

- Fixed `php.misleading-phpdoc-types` so quoted literal-string unions such as `'ask'|'generate'|'skip'` are treated as useful refinements of native `string` instead of as type disagreements.
- Kept incompatible native types visible: the same literal-string PHPDoc on native `int` remains a finding.
- Added a focused consumer-derived regression from `voku/agent-loop#636`; repository CI, PHPStan, self-scan, and PHAR verification cover the release candidate.

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
