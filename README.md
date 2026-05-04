# slop-scan

Deterministic PHP CLI for finding explainable slop patterns in PHP repositories.

`slop-scan` is a static-analysis style heuristic scanner. It is **not** an authorship detector. It reports concrete findings with rule IDs, evidence, scores, and stable occurrence fingerprints so results can be reviewed, compared, and tracked over time.

This repository started from a fork of [`modem-dev/slop-scan`](https://github.com/modem-dev/slop-scan) and was rewritten in PHP with Codex so it fits PHP tooling, packaging, and CI workflows directly.

It ships with AST-backed PHP heuristics, deterministic delta identities, compact baselines, reusable scan caching, and configurable suppressions for real-world repository adoption.

## Requirements

- PHP 8.3+
- Composer

## Quick start

1. Install the latest release PHAR:

```bash
mkdir -p "$HOME/.local/bin"
curl -fsSL https://github.com/voku/slop-scan/releases/latest/download/slop-scan.phar -o "$HOME/.local/bin/slop-scan"
chmod +x "$HOME/.local/bin/slop-scan"
```

2. Scan the current repository:

```bash
"$HOME/.local/bin/slop-scan" scan .
```

3. Pick an output format that matches your workflow:

```bash
"$HOME/.local/bin/slop-scan" scan . --lint
"$HOME/.local/bin/slop-scan" scan . --json
"$HOME/.local/bin/slop-scan" scan . --github
"$HOME/.local/bin/slop-scan" scan . --toon
"$HOME/.local/bin/slop-scan" scan . --ndjson
```

4. Ignore generated or irrelevant paths when needed:

```bash
"$HOME/.local/bin/slop-scan" scan . --ignore 'vendor/**' --ignore 'tests/fixtures/**'
```

The scanner targets PHP source files such as `.php`, `.phtml`, and `.inc`.

## What it ships with

- Deterministic findings with stable occurrence fingerprints for review, delta comparisons, and baseline workflows.
- Built-in PHP heuristics for patterns such as empty catches, error swallowing, blanket suppressions, placeholder bodies, clone clusters, and type-escape hotspots.
- Multiple output targets including text, lint, JSON, GitHub annotations, TOON, and NDJSON.
- Repo-friendly controls including path ignores, per-rule overrides, PHPStan-style `ignoreErrors`, and inline `@slop-scan-ignore` directives.
- Reusable per-file scan caching via `.slop-scan.cache.json` and a `stats` command for repository-level summaries.

## More docs

- [Installation and local builds](docs/installation.md)
- [Delta comparisons and baselines](docs/delta-comparisons.md)
- [Supported files and built-in rules](docs/rules.md)
- [Configuration and suppressions](docs/configuration.md)
- [Report shape](docs/report-shape.md)
- [Development and validation](docs/development.md)
- [Contributing](CONTRIBUTING.md)

## Local development quick start

Install dependencies:

```bash
composer install
```

Run the CLI from the repository checkout:

```bash
php bin/slop-scan.php scan .
```
