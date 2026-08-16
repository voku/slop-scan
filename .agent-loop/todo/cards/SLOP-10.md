# SLOP-10: Compare self-scan output against the last released slop-scan version

- **Ticket:** SLOP-10
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** engine
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-16T08:08:08+00:00
- **Summary:** Upstream lint:self diffs summary and per-rule counts between the working tree and a pinned released binary; the PHP baseline fixture is a four-line stub nothing consumes.
- **Next:** Cheapest of the engine cards; start here.
- **Validation:** composer run test && composer run scan:self
- **Priority:** 3
- **Wave:** 4
- **Format version:** 1

## Agent Task Brief
Upstream scripts/self-scan-stable.ts scans the repository with both the working tree and a pinned previously released binary (devDependency slop-scan-stable: npm:slop-scan@0.1.2), then compares summary metrics and per-rule counts against tests/fixtures/self-scan-stable-baseline.json, with a --update flag to re-record. That catches rule drift between releases, which composer run scan:self cannot: it only reports current findings. tests/fixtures/php-self-scan-baseline.json here is a four-line marker stub ({tool, target, purpose}) that no test reads. The PHAR release artifact makes the pinned-binary half straightforward for PHP.
