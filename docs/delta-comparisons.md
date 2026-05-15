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
