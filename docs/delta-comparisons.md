# Delta comparisons

## Compare two paths directly

```bash
php bin/slop-scan.php delta --base ../main --head . --json
```

Path-based comparisons reuse each tree's configured `scan.cacheFile` when that setting is present in `slop-scan.config.json`.

If either tree keeps its config outside the scan root, point each side at it explicitly:

```bash
php bin/slop-scan.php delta \
  --base ../main \
  --head . \
  --base-config-file infra/githooks/slop-scan.config.json \
  --head-config-file infra/githooks/slop-scan.config.json \
  --json
```

## Change-relative review evidence

When `delta` compares two real paths (not saved reports), it also reports evidence that only exists between a base and a head tree. These findings join the delta as `added` changes; ordinary `scan` and report-to-report `delta` never produce them.

- `php.weakened-tests`: tests that became skipped, lost assertions, gained assertions that cannot fail, or were deleted without a deterministic replacement.
- `php.static-analysis-baseline-growth`: source files that gained suppressed PHPStan errors. It reads `phpstan-baseline.neon` (and `*baseline*.neon` files listed under `includes:` in `phpstan.neon` / `phpstan.neon.dist`), sums the `count` of each entry per source path, and reports every path whose total grew, naming the baseline and the number of added entries. A baseline that did not exist in the base tree is reported once, with the number of errors it accepts. Reordered or regenerated baselines with the same effective counts, and shrinking baselines, stay quiet.

The baseline check is review evidence, not a verdict that baselines are wrong. It uses a small reader for the structure PHPStan writes (including multi-line `rawMessage` blocks), never runs PHPStan, and never modifies the baseline. A baseline that exists but is not in that structure makes `delta` fail with an explicit message instead of being read as "no growth". Psalm XML and `.php` baselines are not read.

## Compare saved reports

```bash
php bin/slop-scan.php scan ../main --json > base.json
php bin/slop-scan.php scan . --json > head.json
php bin/slop-scan.php delta --base-report base.json --head-report head.json --json
```

## Generate and use a baseline

```bash
php bin/slop-scan.php scan . --baseline-file slop-baseline.json --generate-baseline
php bin/slop-scan.php scan . --baseline-file slop-baseline.json --github
```

If `slop-scan.config.json` already defines `scan.baselineFile`, you can omit `--baseline-file` and keep using the configured baseline path:

```bash
php bin/slop-scan.php scan . --generate-baseline
php bin/slop-scan.php scan . --github
```

The generated baseline is intentionally compact: it stores only finding metadata and fingerprints needed to suppress existing findings, not the full scanned file inventory.

Baseline metadata also records the effective active rule IDs and an explicit rule-semantics compatibility version. Baseline-aware `scan` fails closed before classifying a delta when that surface no longer matches, because scanner changes must not be reported as findings introduced by the candidate. A legacy baseline without compatibility metadata must be reviewed and regenerated explicitly. Generic `delta` comparisons remain independent from this baseline guard.

## Fail on selected delta statuses

```bash
php bin/slop-scan.php delta --base-report base.json --head-report head.json --fail-on added
```

## Supported command options

- `scan`
- `delta`
- `--json`
- `--lint`
- `--github`
- `--ignore`
- `--config-file`
- `--cache-file`
- `--baseline-file`
- `--generate-baseline`
- `--base`
- `--head`
- `--base-config-file`
- `--head-config-file`
- `--base-report`
- `--head-report`
- `--fail-on`
