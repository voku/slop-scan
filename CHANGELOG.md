# Changelog

All notable changes to this project will be documented in this file.

## 0.1.0 - 2026-05-02

- Forked the original idea from [`modem-dev/slop-scan`](https://github.com/modem-dev/slop-scan).
- Rewrote the implementation in PHP with Codex for PHP-native CLI, packaging, and CI workflows.
- Added deterministic scan reports with stable finding fingerprints, delta comparison support, and baseline-based suppression for existing findings.
- Shipped built-in PHP heuristics, JSON/lint/GitHub reporters, and PHAR packaging support.
