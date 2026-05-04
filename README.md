# slop-scan

Deterministic PHP CLI for finding explainable slop patterns in PHP repositories.

`slop-scan` is a static-analysis style heuristic scanner. It is **not** an authorship detector. It reports concrete findings with rule IDs, evidence, scores, and stable occurrence fingerprints so results can be reviewed, compared, and tracked over time.

This repository started from a fork of [`modem-dev/slop-scan`](https://github.com/modem-dev/slop-scan) and was rewritten in PHP with Codex so it fits PHP tooling, packaging, and CI workflows directly.

## Requirements

- PHP 8.3+
- Composer

## Install

Install the latest release PHAR directly:

```bash
mkdir -p "$HOME/.local/bin"
curl -fsSL https://github.com/voku/slop-scan/releases/latest/download/slop-scan.phar -o "$HOME/.local/bin/slop-scan"
chmod +x "$HOME/.local/bin/slop-scan"
"$HOME/.local/bin/slop-scan" scan .
```

Install dependencies for local development:

```bash
composer install
```

Run the CLI from the repository checkout:

```bash
php bin/slop-scan.php scan .
```

Build a PHAR:

```bash
composer run phar:build
php dist/slop-scan.phar scan .
```

## Quick start

1. Install dependencies:

```bash
composer install
```

2. Scan the current repository:

```bash
php bin/slop-scan.php scan .
```

3. Pick an output format that matches your workflow:

```bash
php bin/slop-scan.php scan . --lint
php bin/slop-scan.php scan . --json
php bin/slop-scan.php scan . --github
```

4. Ignore generated or irrelevant paths when needed:

```bash
php bin/slop-scan.php scan . --ignore 'vendor/**' --ignore 'tests/fixtures/**'
```

5. Reuse the default cache across repeated local runs:

```bash
php bin/slop-scan.php scan .
```

6. Create a baseline when you want CI to fail only on newly introduced findings:

```bash
php bin/slop-scan.php scan . --baseline-file slop-baseline.json --generate-baseline
php bin/slop-scan.php scan . --baseline-file slop-baseline.json --github
```

The generated baseline is intentionally compact: it stores only finding metadata and fingerprints needed to suppress existing findings, not the full scanned file inventory.

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
- `--cache-file`
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

## What the built-in rules check and why

`slop-scan` focuses on explainable, reviewable heuristics. These rules try to catch patterns that often show up in rushed, weakly reviewed, or partially cleaned-up code:

| Rule | What it checks | Why it matters |
| --- | --- | --- |
| `php.empty-catch` | `catch` blocks with no statements | Exceptions disappear silently and make failures harder to debug. |
| `php.error-swallowing` | `catch` blocks that log/print and continue without `throw` or `return` | Errors are acknowledged but not handled, so broken execution keeps going. |
| `php.blanket-static-analysis-suppressions` | Broad `@phpstan-ignore`, `@psalm-suppress`, and similar comments | Blanket suppressions hide real problems and reduce trust in static analysis. |
| `php.excessive-static-analysis-suppressions` | Files with more suppression comments than the configured threshold | A file full of suppressions often signals design debt or papered-over typing issues. |
| `php.stacked-static-analysis-suppressions` | Back-to-back suppression comments above one code site | Stacked ignores are a strong smell that one line is resisting cleanup. |
| `php.commented-out-code` | Comments that look like disabled code | Dead code in comments adds noise and creates doubt about what is still relevant. |
| `php.catch-default-fallbacks` | `catch` blocks that return empty literals such as `null`, `[]`, `''`, `false`, or `0` | Default fallbacks can silently turn real failures into misleading “success” values. |
| `php.debug-output` | Calls like `var_dump()`, `print_r()`, `dd()`, or `ray()` left in source | Debug leftovers usually should not ship in production code. |
| `php.mock-heavy-tests-without-assertions` | Tests that mostly build mocks but do not assert behavior | These tests look busy but often do not protect behavior. |
| `php.misleading-phpdoc-types` | PHPDoc param/return types that either disagree with or merely duplicate native types | Misleading docs undermine trust, while redundant docs add noise without extra type value. |
| `php.placeholder-comments` | Comments such as TODO, FIXME, HACK, placeholder, temporary | These markers often reveal unfinished or intentionally deferred work. |
| `php.pass-through-wrappers` | Functions that mostly forward input to another function | Thin wrappers can indicate unnecessary indirection and generated-looking structure. |
| `php.directory-fanout-hotspot` | Directories with unusually high PHP file counts | Large clusters of files can indicate sprawl and review-unfriendly structure. |
| `php.over-fragmentation` | Directories with many tiny PHP files | Excessively tiny files can make simple behavior harder to follow. |
| `php.duplicate-function-signatures` | Repeated function signatures across the repository | Repetition can point to copy-paste design and missed abstraction opportunities. |

The tool is intentionally heuristic: a finding is a prompt for review, not a verdict.

## Configuration

`slop-scan` reads JSON config from `slop-scan.config.json` or `repo-slop.config.json` in the scan root.

Scans now reuse unchanged per-file analysis by default through `.slop-scan.cache.json` in the scan root. Use `--cache-file` to override that location.

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

JSON scan output includes:

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

Baseline files are smaller than full JSON scan reports. They contain:

- `metadata`
- `summary.findingCount`
- `findings`

That keeps baseline adoption practical for existing repositories: commit the current findings once, then let CI fail only on newly introduced fingerprints.

## Development

Run local validation:

```bash
composer validate --strict
composer run lint
composer run analyse
composer run test
composer run scan:self
composer run phar:build
```

Run mutation testing with Infection and PHPStan-backed escaped-mutant checks:

```bash
composer run mutate
```

The repository dogfoods `slop-scan` in CI by scanning the whole checkout directly, without a committed baseline file, so pull requests must keep the repository clean enough to pass the same heuristics it ships.

The implementation lives in PSR-4 class files under `src/`, organized by responsibility (for example `Contract/`, `Fact/`, `Model/`, `Reporter/`, `Rule/`, `Runtime/`, and `Support/`); tests live in `tests/`.
