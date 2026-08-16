# Upstream rule parity

This repository started as a PHP rewrite of
[`modem-dev/slop-scan`](https://github.com/modem-dev/slop-scan), a
TypeScript/JavaScript scanner. The two tools have diverged: PHP has heuristics
that make no sense in TypeScript (PHPDoc/native type disagreement, blanket
`@phpstan-ignore` suppressions, `var_dump()` leftovers), and TypeScript has
heuristics that need a real adaptation decision before they mean anything in
PHP.

This page records where the ports stand, for rules and for engine features.
Each open row has a board card; see
[the agent-loop workflow](agent-loop-workflow.md) for how to pick one up.

## Ported

| Upstream rule | PHP rule | Notes |
| --- | --- | --- |
| `defensive.empty-catch` | `php.empty-catch` | |
| `defensive.error-obscuring` | `php.error-obscuring-catch`, `php.exception-wrap-without-previous` | Split in two: PHP distinguishes a replacement exception from a message-only wrap that drops `previous`. |
| `defensive.error-swallowing` | `php.error-swallowing` | |
| `defensive.promise-default-fallbacks` | `php.catch-default-fallbacks` | PHP has no promise chains in the language, so the same failure-suppression habit is caught at `try`/`catch`. |
| `defensive.stringified-unknown-errors` | `php.catch-returns-exception-message` | Partial; see `SLOP-6`. |
| `comments.placeholder-comments` | `php.placeholder-comments` | |
| `structure.directory-fanout-hotspot` | `php.directory-fanout-hotspot` | |
| `structure.duplicate-function-signatures` | `php.duplicate-function-signatures` | |
| `structure.over-fragmentation` | `php.over-fragmentation` | |
| `structure.pass-through-wrappers` | `php.pass-through-wrappers` | |
| `api.generic-status-envelopes` | `php.generic-status-envelopes` | Ported under `SLOP-1`; deviations below. |

`php.commented-out-code`, `php.blanket-static-analysis-suppressions`,
`php.excessive-static-analysis-suppressions`,
`php.stacked-static-analysis-suppressions`, `php.debug-output`,
`php.mock-heavy-tests-without-assertions`, `php.magic-numbers`,
`php.misleading-phpdoc-types`, `php.return-constant-stub`,
`php.placeholder-method-bodies`, `php.clone-cluster`,
`php.type-escape-hotspots` and `markdown.low-signal` have no upstream
counterpart.

## Open

| Upstream rule | Proposed PHP rule | Card |
| --- | --- | --- |
| `types.generic-record-casts` | `php.generic-array-casts` | `SLOP-2` |
| `tests.duplicate-mock-setup` | `php.duplicate-mock-setup` | `SLOP-3` |
| `structure.barrel-density` | `php.reexport-barrel` | `SLOP-4` |
| `defensive.async-noise` | `php.await-noise` | `SLOP-5` |
| `defensive.stringified-unknown-errors` | extend `php.catch-returns-exception-message` | `SLOP-6` |

`SLOP-3` and `SLOP-4` are blocked on `SLOP-13`: their upstream facts
(`repo.testMockDuplication`, `file.exportSummary`) have no PHP counterpart.

`SLOP-4` and `SLOP-5` are the two ports that may not be worth making. PHP has
no re-export syntax and no language-level async, so both need an analog chosen
on purpose rather than translated. Upstream ranked them #8 and #7 of 11 by
signal, and `barrel-density` fired on 5 of 5 mature OSS repositories, so a
naive port would mostly produce noise. Record the decision on the card either
way.

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

`SLOP-9` deserves particular attention. It is what produces the
"signal rank #2 of 9, hit rate 5/6 AI repos vs 2/5 mature OSS repos" figures in
the upstream rule docs, and the priority order of the rule cards above was
taken from those figures rather than measured here. `AGENTS.md` tells
contributors not to tune heuristics to a single fixture or repository; this is
the machinery that makes that instruction checkable.

Where this fork is ahead of upstream, and no port is wanted:

- CLI surface. Upstream has `scan` and `delta` with `--json`, `--lint` and
  `--ignore`. This fork adds `stats`, `--github`, `--toon`, `--ndjson`,
  baselines, a reusable scan cache, `--config-file`, and `maxFindings` /
  `minScore` / `pathFilters` scan defaults.
- Suppressions. Inline `@slop-scan-ignore` directives, PHPStan-style
  `ignoreErrors`, and per-path `overrides` have no upstream equivalent.
- PHP-only heuristics: PHPDoc/native type disagreement, blanket and stacked
  static-analysis suppressions, debug-output leftovers, and `markdown.low-signal`.

## Deviations in `php.generic-status-envelopes`

Two upstream properties were changed on purpose:

- **Severity is `medium`, not upstream's `strong`.** This repository publishes
  severity as part of the report shape and only uses `weak` and `medium`.
  Introducing a third value is a compatibility change that belongs in its own
  task, not in a rule port.
- **Findings are per site, not one capped file score.** Upstream adds 2 points
  per envelope and caps the file at 8. Here each site is its own finding worth
  2.0, matching `php.catch-default-fallbacks` and `php.magic-numbers`. A cap
  would hide occurrences, and every occurrence needs its own delta identity for
  baselines to work.

One upstream property was **not** carried over and is a genuine gap rather
than a choice, tracked as `SLOP-7`: upstream inspects each literal's parent
node and classifies the match as returned, assigned, or emitted through a
`.json(...)` response call, then reports that kind as evidence. The PHP rule
reports which keys matched but not where the envelope goes, so an envelope
crossing an HTTP boundary reads the same as one assigned to a local.

The rule additionally accepts a boolean `status` key, which upstream does not,
because `['status' => true, 'message' => ...]` is a common PHP spelling of the
same shape. Requiring a literal `true`/`false` keeps `['status' => 'archived']`
quiet.
