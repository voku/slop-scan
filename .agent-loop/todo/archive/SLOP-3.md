# SLOP-3: Port upstream tests.duplicate-mock-setup as php.duplicate-mock-setup

- **Ticket:** SLOP-3
- **Lane:** VERIFY
- **Status:** Done
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-18T18:00:00+00:00
- **Summary:** Repo-scope fact fingerprinting PHPUnit mock/stub setup shapes repeated across three or more test files.
- **Next:** Implemented and merged in PR #35.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 3
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
Ported as php.duplicate-mock-setup (repo scope). file.testMockSetups was added to the existing php.structure provider and repo.duplicateMockSetups to the existing FunctionDuplicationFactProvider, so no new provider and no SLOP-13 prerequisite were needed. A setup shape must appear in at least three distinct test files and bind to a PHPUnit mock or stub; bare createMock/createStub declarations, tearDown-only cleanup and one- or two-file clusters stay quiet. Distinct from php.mock-heavy-tests-without-assertions, which is single-file and assertion-based.
