# Near-copy drift detection: experiment result (issue #62)

**Outcome: no new rule.** `php.clone-cluster` stays the only duplication rule.

A bounded near-copy detector is cheap to run, but on real code nearly everything it finds is an intentional variant. The few plausible drift candidates are not separable from that noise without per-repository tuning, which the project rules out.

## What was tested

A read-only harness ([`near-copy-drift-harness.php.txt`](near-copy-drift-harness.php.txt); not part of `src/`, no rule registered) built on the scanner's own function summaries:

- function and method bodies of at least 40 tokens, with variables alpha-renamed so local renames are never a divergence;
- a pair is a candidate at token edit distance 1..3 and at most 5% of the body, so exact clones are never candidates;
- candidate pairs come from buckets keyed by the first 16 and the last 16 normalized tokens, with a hard comparison budget and a truncation flag;
- candidates are compared with a bounded Levenshtein distance;
- a second, stricter mode keeps only pairs where one side has an identical sibling and the other is alone (the "majority copy / odd one out" idea from upstream `SL111`);
- `php.clone-cluster` is replicated, including its same-namespace and same-directory filters, to measure overlap.

Run it with `SLOP_SCAN_ROOT=$PWD php docs/experiments/near-copy-drift-harness.php.txt <dir>`.

## Cost

| Corpus | Functions | Candidates | Comparisons (bucketed) | Comparisons (exhaustive) | Pairs (bucketed / exhaustive) |
| --- | ---: | ---: | ---: | ---: | ---: |
| `voku/slop-scan` `src/` | 523 | 245 | 94 | 29,890 | 1 / 1 |
| `nikic/php-parser` `lib/` | 1,249 | 232 | 84 | 26,796 | 2 / 2 |
| `symfony/console` | 937 | 313 | 42 | 48,828 | 6 / 7 |
| `infection/infection` `src/` | 1,904 | 490 | 44 | 119,805 | 5 / 8 |
| whole `vendor/` tree (non-test) | 20,805 | 7,011 | 10,431 | 24,573,555 | 1,317 / 1,476 |

- Comparison cost is not the problem for the bucketed search: 10,431 comparisons for 7,011 candidates, under one second of the 19.8 s run (parsing dominates). Peak memory was 162 MB.
- Bucketing finds 89% of the exhaustive pairs on the large corpus (and 5 of 8 on the infection corpus). Exhaustive comparison needed 24.6M comparisons, 58 s and 2.7 GB, so it does not scale.
- A naive comparison budget is biased. Exhaustive search stopped at 2M comparisons found 6 pairs, all in one file. Any shipped version would need the bucketed search plus an explicit truncation diagnostic.

## Signal

Overlap with `php.clone-cluster` is negligible (4 of 1,317 pairs on the large corpus), so near-copy findings would be unique. The problem is what they are.

- **Small and medium repositories (14 pairs read in full):** at most two look like possible drift (`ArgvInput::addShortOption` vs `ArrayInput::addShortOption` throw different exception classes; the Fish and Zsh completion `write` methods differ in one concatenation). The rest are intentional sibling variants: mutator classes that each differ by one node type, builders, and exception factories whose messages differ by one word.
- **Large corpus (36 of 1,313 pairs sampled at random):** none is a plausible drift. 22 of the 36 are generated `thecodingmachine/safe` wrappers or `phpdoc-parser` `__set_state` AST nodes. The remainder are plan-document classes with one different error message, and negated assertion helpers (`contains` / `notContains`) whose differing messages are correct.
- **Majority/minority mode (14 of 249 pairs sampled):** none is plausible. Every pair differs by an array key or field name (`__set_state`, per-table `meta` queries), which is always intentional.

The issue's own false-positive boundary (quiet for deliberately parameterized variants and for generic boilerplate) is exactly the population that dominates the results. Separating it would need exclusions for generated code, AST node families and sibling-by-design classes, which would tune the rule to particular repositories.

## Decision

The stop rule in the issue applies: the detector produces mostly noisy findings and needs bucketing to stay affordable. Keep `php.clone-cluster` as the simpler solution and ship nothing.

Revisit only if a consumer repository supplies a concrete class of real drift (for example, sibling methods that must throw the same exception type) that a narrower, purpose-built check could target without a general fuzzy matcher.
