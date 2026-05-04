# Contributing to slop-scan

Thanks for helping improve `slop-scan`.

This project is a deterministic PHP CLI for explainable slop heuristics on PHP repositories. Keep changes reproducible, stable, and evidence-based.

## Development setup

Requirements:

- PHP 8.3+
- Composer

Install dependencies:

```bash
composer install
```

Run the CLI locally:

```bash
php bin/slop-scan.php scan . --lint
```

## Local validation

Run the standard validation suite before opening a PR:

```bash
composer validate --strict
composer run lint
composer run analyse
composer run test
composer run scan:self
```

## Pull requests

A good PR usually includes:

- focused code and docs changes
- updated tests when behavior changes
- an explanation of intentional report-shape or scoring changes
