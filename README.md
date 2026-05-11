[![CI](https://github.com/voku/slop-scan/actions/workflows/ci.yml/badge.svg)](https://github.com/voku/slop-scan/actions/workflows/ci.yml)
[![Latest Stable Version](https://poser.pugx.org/voku/slop-scan/v/stable)](https://packagist.org/packages/voku/slop-scan) 
[![License](https://poser.pugx.org/voku/slop-scan/license)](https://packagist.org/packages/voku/slop-scan)
[![Donate to this project using Paypal](https://img.shields.io/badge/paypal-donate-yellow.svg)](https://www.paypal.me/moelleken)
[![Donate to this project using Patreon](https://img.shields.io/badge/patreon-donate-yellow.svg)](https://www.patreon.com/voku)

# 💩 slop-scan

slop-scan: Deterministic PHP CLI for finding explainable slop patterns in PHP repositories.

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

The scanner targets PHP source files such as `.php`, `.phtml`, and `.inc`, plus Markdown docs such as `.md` and `.markdown`.

If your repository keeps its config outside the scan root, point the scan at it explicitly:

```bash
"$HOME/.local/bin/slop-scan" scan . --config-file infra/githooks/slop-scan.config.json
```

## What it ships with

- Deterministic findings with stable occurrence fingerprints for review, delta comparisons, and baseline workflows.
- Built-in heuristics for PHP patterns such as empty catches, error swallowing, blanket suppressions, magic numbers, placeholder bodies, clone clusters, and type-escape hotspots, plus Markdown checks for low-signal process docs.
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

## Portable agent skills

This repository keeps vendor-neutral agent skills in [`.ai/skills/manifest.yaml`](.ai/skills/manifest.yaml).

Those files are the repo-owned source of truth for coding agents such as Copilot, Codex, Gemini, or similar tools. Each skill is plain YAML that defines when to use it, which repository command to run, what inputs it expects, what output to prefer, and how to handle common failures.

Current portable skills:

- `scan-php-slop` for deterministic PHP repository scans
- `validate-slop-scan-repo` for scan-readiness checks and slop-scan troubleshooting on a target PHP repository
- `interpret-slop-scan-json` for machine-readable report review
