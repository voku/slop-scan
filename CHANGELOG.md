# Changelog

All notable changes to this project will be documented in this file.

## 0.1.2 - 2026-05-04

- Forked the original idea from [`modem-dev/slop-scan`](https://github.com/modem-dev/slop-scan).
- Rewrote the tool in PHP for native CLI usage, Composer packaging, PHAR distribution, and CI workflows.
- Shipped deterministic scan reports with stable finding fingerprints, delta comparison support, compact baselines, and reusable scan caching enabled by default.
- Added built-in PHP heuristics backed by AST parsing and parser-backed PHPDoc analysis, including clone-cluster, placeholder stub/body, type-escape hotspot, misleading PHPDoc, and catch-fallback detection with tuned noise reduction.
- Added JSON, lint, GitHub, TOON, and NDJSON reporters, richer finding metadata, and a `stats` command for repository-level summaries.
- Added configuration and suppression support including ignores, rule overrides, PHPStan-style `ignoreErrors`, and inline `@slop-scan-ignore` directives.
- Added focused docs, PHAR release automation, and fixture plus in-process CLI coverage for self-scan and rule behavior.
