# Upstream rule parity

This repository started as a PHP rewrite of
[`modem-dev/slop-scan`](https://github.com/modem-dev/slop-scan), a
TypeScript/JavaScript scanner. The two tools have diverged: PHP has heuristics
that make no sense in TypeScript (PHPDoc/native type disagreement, blanket
`@phpstan-ignore` suppressions, `var_dump()` leftovers), and TypeScript has
heuristics that need a real adaptation decision before they mean anything in
PHP.

This page records where the ports stand, for rules and for engine features.
Rule parity means preserving useful review signal, not reproducing TypeScript
AST shapes or adding engine architecture merely because upstream has it.

## Ported

| Upstream rule | PHP rule | Notes |
| --- | --- | --- |
| `defensive.empty-catch` | `php.empty-catch` | |
| `defensive.error-obscuring` | `php.error-obscuring-catch`, `php.exception-wrap-without-previous` | Split in two: PHP distinguishes a replacement exception from a message-only wrap that drops `previous`. |
| `defensive.error-swallowing` | `php.error-swallowing` | |
| `defensive.promise-default-fallbacks` | `php.catch-default-fallbacks` | PHP has no promise chains in the language, so the same failure-suppression habit is caught at `try`/`catch`. |
| `defensive.stringified-unknown-errors` | `php.catch-returns-exception-message` | PHP catches direct returns plus generic error/message assignments and array payload entries inside the catch. It does not try to reproduce TypeScript's conditional-expression syntax. |
| `comments.placeholder-comments` | `php.placeholder-comments` | |
| `structure.directory-fanout-hotspot` | `php.directory-fanout-hotspot` | |
| `structure.duplicate-function-signatures` | `php.duplicate-function-signatures` | |
| `structure.over-fragmentation` | `php.over-fragmentation` | |
| `structure.pass-through-wrappers` | `php.pass-through-wrappers` | |
| `types.generic-record-casts` | `php.generic-array-casts` | PHP adaptation flags explicit runtime conversion (`json_decode(..., true)` / `(array)`) only when assigned to vague bag variables. PHPDoc-only declarations are intentionally not treated as runtime evidence. |
| `api.generic-status-envelopes` | `php.generic-status-envelopes` | Ported under `SLOP-1`; context classification from `SLOP-7` distinguishes returned, JSON-response, and assigned/local envelopes. |

`php.commented-out-code`, `php.blanket-static-analysis-suppressions`,
`php.excessive-static-analysis-suppressions`,
`php.stacked-static-analysis-suppressions`, `php.debug-output`,
`php.mock-heavy-tests-without-assertions`, `php.magic-numbers`,
`php.misleading-phpdoc-types`, `php.return-constant-stub`,
`php.placeholder-method-bodies`, `php.clone-cluster`,
`php.type-escape-hotspots` and `markdown.low-signal` have no upstream
counterpart.

## Open rule adaptations

| Upstream rule | Proposed PHP rule | Card |
| --- | --- | --- |
| `tests.duplicate-mock-setup` | `php.duplicate-mock-setup` | `SLOP-3` |
| `structure.barrel-density` | decision: useful PHP analogue or reject | `SLOP-4` |
| `defensive.async-noise` | decision: useful PHP analogue or reject | `SLOP-5` |

`SLOP-4` and `SLOP-5` may correctly end with **no PHP rule**. PHP has no
re-export syntax and no language-level async. An adaptation should only land if
a concrete PHP pattern carries the same review signal without becoming a broad
style preference.

`SLOP-3` needs a rule-specific repository fact for repeated PHPUnit mock/setup
shapes. That fact should be built with the rule, not as a generic engine-parity
project.

## Feature parity

Rules are not the only thing upstream has. These are engine and tooling
features with no PHP counterpart:

| Upstream | Where | Card |
| --- | --- | --- |
| Plugin system for third-party rule packs | `src/plugin.ts`, config `extends`/`plugins`, `Registry.registerPlugin` | `SLOP-8` |
| Rule-signal benchmark against a pinned AI-vs-OSS cohort | `src/benchmarks/`, `scripts/benchmark-*.ts` | `SLOP-9` |
| Self-scan compared against the last released binary | `scripts/self-scan-stable.ts`, `lint:self` | `SLOP-10` |
| Per-rule delta strategy (`byPath` / `byLocations` / semantic keys) | `src/rule-delta.ts` | `SLOP-11` |
| A README per rule with examples and fix guidance | `src/rules/*/README.md`, `rule-signal-readme.ts` | `SLOP-12` |
| Facts for the unported structure/test rules | `src/facts/exports.ts`, `test-mock-setups.ts`, `test-duplication.ts` | `SLOP-13` |

Those rows are an inventory, not a requirement that this fork become the same
engine. The PHP fork already has useful behavior upstream does not, and feature
work should be driven by an actual PHP consumer/problem rather than parity by
itself.

Where this fork is ahead of upstream, and no reverse port is wanted:

- CLI surface. Upstream has `scan` and `delta` with `--json`, `--lint` and
  `--ignore`. This fork adds `stats`, `--github`, `--toon`, `--ndjson`,
  baselines, a reusable scan cache, `--config-file`, and `maxFindings` /
  `minScore` / `pathFilters` scan defaults.
- Suppressions. Inline `@slop-scan-ignore` directives, PHPStan-style
  `ignoreErrors`, and per-path `overrides` have no upstream equivalent.
- PHP-only heuristics: PHPDoc/native type disagreement, blanket and stacked
  static-analysis suppressions, debug-output leftovers, and `markdown.low-signal`.

## PHP adaptation choices

### `php.generic-status-envelopes`

Two upstream properties were changed on purpose:

- **Severity is `medium`, not upstream's `strong`.** This repository publishes
  severity as part of the report shape and only uses `weak` and `medium`.
  Introducing a third value is a compatibility change that belongs in its own
  task, not in a rule port.
- **Findings are per site, not one capped file score.** Upstream adds 2 points
  per envelope and caps the file at 8. Here each site is its own finding worth
  2.0, matching other PHP site-level rules and keeping each occurrence visible
  to baselines.

The upstream context signal is now carried over: evidence identifies a direct
return, a direct `->json(...)` / `new JsonResponse(...)` boundary, or an
assigned/local array. The PHP rule additionally accepts a boolean `status` key,
which upstream does not, because `['status' => true, 'message' => ...]` is a
common PHP spelling of the same shape. Requiring a literal `true`/`false` keeps
`['status' => 'archived']` quiet.

### `php.generic-array-casts`

The TypeScript rule recognizes `Record<string, unknown>` assertions on vague
variables. PHP does not have the same assertion syntax. The adaptation therefore
uses observable runtime conversions as the forcing signal:

- `json_decode($raw, true)` or `json_decode($raw, associative: true)`;
- `(array) $value`;
- only when assigned to deliberately vague bag names such as `$data`, `$payload`,
  `$parsed`, `$record`, `$result`, or `$config`.

A domain-named variable remains quiet, and PHPDoc alone is not enough to fire
the rule. This keeps the rule distinct from `php.type-escape-hotspots`, which is
a file-density heuristic requiring concentrated `mixed` plus casts.

### `php.catch-returns-exception-message`

The existing direct-return behavior and evidence stay stable. The adaptation
adds the two useful upstream contexts that PHP can express directly inside a
`catch`: assignment to generic error/message variables and generic
error/message array entries. Domain recovery calls and previous-preserving
throws are not treated as stringification merely because they reference the
caught exception.
