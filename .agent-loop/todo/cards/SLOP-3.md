# SLOP-3: Port upstream tests.duplicate-mock-setup as php.duplicate-mock-setup

- **Ticket:** SLOP-3
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-16T00:22:24+00:00
- **Summary:** Repo-scope fact fingerprinting PHPUnit mock/stub setup shapes repeated across three or more test files.
- **Next:** Add a repo-scope fact provider for test mock setup fingerprints.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 3
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
Upstream: src/rules/duplicate-mock-setup (family tests, severity medium, file scope, requires repo.testMockDuplication). Upstream fingerprints statement-level mock/setup shapes in test files and reports a file when one shape appears in three or more test files, filtering generic framework labels (vi.mock, jest.mock, spyOn) and cleanup-only calls. PHP adaptation: fingerprint PHPUnit createMock/createStub/getMockBuilder chains plus ->method(...)->willReturn(...) shapes; filter bare createMock() declarations and tearDown-only calls. Distinct from the existing php.mock-heavy-tests-without-assertions, which is single-file and assertion-based.
