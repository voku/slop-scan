# SLOP-3: Port upstream tests.duplicate-mock-setup as php.duplicate-mock-setup

- **Ticket:** SLOP-3
- **Lane:** DOING
- **Status:** Selected
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-16T09:20:00+00:00
- **Summary:** Detect the same meaningful PHPUnit mock/stub behavior setup repeated across at least three distinct test files.
- **Next:** Human approval of Contract revision 2: use one repo-scope rule over existing file.functionSummaries; no dedicated repo fact/provider and no Analyzer lifecycle change.
- **Validation:** composer validate --strict && composer run lint && composer run analyse && composer run test && composer run scan:self && composer run phar:build
- **Priority:** 3
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
The first approved topology was falsified before validation: Analyzer executes file rules before repo providers, so a file-scope rule cannot consume a repo fact generated later. Contract r2 therefore proposes the smaller design: one repo-scope `php.duplicate-mock-setup` rule directly reads existing parser-normalized `file.functionSummaries`, clusters repeated PHPUnit setup shapes, and emits findings with concrete test-file locations. This avoids a dedicated fact provider and any Analyzer/Scheduler change. Bare mock creation, `tearDown()`-only setup, and clusters in fewer than three files remain excluded.
