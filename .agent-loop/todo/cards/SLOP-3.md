# SLOP-3: Port upstream tests.duplicate-mock-setup as php.duplicate-mock-setup

- **Ticket:** SLOP-3
- **Lane:** DOING
- **Status:** In Progress
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-16T09:06:00+00:00
- **Summary:** Detect the same meaningful PHPUnit mock/stub behavior setup repeated across at least three distinct test files.
- **Next:** Implement the approved rule-specific repo fact and php.duplicate-mock-setup without a generic parity prerequisite layer.
- **Validation:** composer validate --strict && composer run lint && composer run analyse && composer run test && composer run scan:self && composer run phar:build
- **Priority:** 3
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
Upstream splits file-level mock setup extraction from repo-level duplication clustering. The PHP fork should preserve the useful cross-file signal, not the upstream fact topology. A focused repo fact may consume existing `file.text` and `repo.files`, extract PHPUnit setup shapes, and publish `repo.testMockDuplication` directly. Fingerprints should include statically known mock/stub target classes plus behavior-chain shape where possible. Ignore bare mock declarations, `tearDown()`-only setup, and clusters present in fewer than three files. Keep this distinct from `php.mock-heavy-tests-without-assertions`.
