# SLOP-3: Port upstream tests.duplicate-mock-setup as php.duplicate-mock-setup

Port the upstream duplicated-test-setup signal as a PHP-native rule without importing upstream engine architecture.

Discovery against the current Analyzer lifecycle invalidated the first topology: file-scope rules run before repo-scope fact providers, so a file rule cannot consume a repo fact produced later in the same analysis. Changing Analyzer ordering would be a much larger product change than this rule deserves.

The revised PHP adaptation is therefore one repo-scope rule that reads the parser-normalized `file.functionSummaries` already produced by `php.structure`, clusters meaningful PHPUnit mock/stub setup shapes across files, and emits repo findings with concrete locations.

Implementation boundary:

- no new fact provider and no Analyzer/Scheduler lifecycle change;
- fingerprint meaningful PHPUnit behavior setup such as `->method(...)->willReturn(...)`, expectation chains, and configured `getMockBuilder(...)` / `createConfiguredMock(...)` shapes;
- use the mock/stub target class when statically available so common method names alone do not create noisy clusters;
- ignore bare `createMock()` / `createStub()` declarations and setup that exists only in `tearDown()`;
- require the same canonical shape in at least three distinct files;
- keep `php.mock-heavy-tests-without-assertions` unchanged; it answers a different single-file question.

SLOP-13 is not a prerequisite for this slice. Any remaining structure-rule prerequisite must justify itself independently.
