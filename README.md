# slop-scan

Deterministic PHP CLI for finding explainable slop patterns in PHP repositories.

`slop-scan` is a static-analysis style heuristic scanner. It is **not** an authorship detector. It reports concrete findings with rule IDs, evidence, scores, and stable occurrence fingerprints so results can be reviewed, compared, and tracked over time.

## Requirements

- PHP 8.3+
- Composer

## Install

Install dependencies for local development:

```bash
composer install
```

Run the CLI from the repository checkout:

```bash
php bin/slop-scan.php scan .
```

## Quick start

Scan the current repository:

```bash
php bin/slop-scan.php scan .
```

Scan in lint mode:

```bash
php bin/slop-scan.php scan . --lint
```

Emit JSON:

```bash
php bin/slop-scan.php scan . --json
```

Emit GitHub Actions annotations:

```bash
php bin/slop-scan.php scan . --github
```

Ignore paths:

```bash
php bin/slop-scan.php scan . --ignore 'vendor/**' --ignore 'tests/fixtures/**'
```

## Delta comparisons

Compare two paths directly:

```bash
php bin/slop-scan.php delta --base ../main --head . --json
```

Compare saved reports:

```bash
php bin/slop-scan.php scan ../main --json > base.json
php bin/slop-scan.php scan . --json > head.json
php bin/slop-scan.php delta --base-report base.json --head-report head.json --json
```

Generate a scan baseline, then fail only on findings introduced after that baseline:

```bash
php bin/slop-scan.php scan . --baseline-file slop-baseline.json --generate-baseline
php bin/slop-scan.php scan . --baseline-file slop-baseline.json --github
```

Fail CI when selected delta statuses are present:

```bash
php bin/slop-scan.php delta --base-report base.json --head-report head.json --fail-on added
```

Supported command/options:

- `scan`
- `delta`
- `--json`
- `--lint`
- `--github`
- `--ignore`
- `--baseline-file`
- `--generate-baseline`
- `--base`
- `--head`
- `--base-report`
- `--head-report`
- `--fail-on`

## Supported files

The PHP implementation scans:

- `.php`
- `.phtml`
- `.inc`

## Current built-in rules

- `php.empty-catch`
- `php.error-swallowing`
- `php.placeholder-comments`
- `php.pass-through-wrappers`
- `php.directory-fanout-hotspot`
- `php.over-fragmentation`
- `php.duplicate-function-signatures`

## Configuration

`slop-scan` reads JSON config from `slop-scan.config.json` or `repo-slop.config.json` in the scan root.

```json
{
  "ignores": ["**/vendor/**", "**/.git/**"],
  "rules": {
    "php.placeholder-comments": { "enabled": true, "weight": 0.5 },
    "php.directory-fanout-hotspot": { "options": { "fileCount": 12 } }
  },
  "overrides": []
}
```

## Report shape

JSON output includes:

- `metadata`
- `rootDir`
- `config`
- `summary`
- `files`
- `directories`
- `findings`
- `fileScores`
- `directoryScores`

Each finding includes rule identity, severity, scope, message, evidence, score, locations, path, and `deltaIdentity` occurrence fingerprints.

## Development

Run local validation:

```bash
composer validate --strict
composer run lint
composer run test
composer run scan:self
```

The implementation lives in PSR-4 class files under `src/`; `src/bootstrap.php` remains the lightweight fallback bootstrap/autoloader and tests live in `tests/`.
