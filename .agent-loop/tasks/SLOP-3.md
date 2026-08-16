# SLOP-3: Port upstream tests.duplicate-mock-setup as php.duplicate-mock-setup

Port the upstream duplicated-test-setup signal as a PHP-native rule without importing upstream engine architecture.

The PHP adaptation should detect repeated PHPUnit mock/stub behavior setup that appears in at least three distinct test files. The repo-level fact owns cross-file clustering; the rule projects a stable, explainable finding back onto each affected test file.

Implementation boundary:

- consume existing `file.text` plus `repo.files`; do not add a generic `file.testMockSetups` parity layer only because upstream has one;
- fingerprint meaningful PHPUnit behavior setup such as `->method(...)->willReturn(...)`, expectation chains, and configured `getMockBuilder(...)` chains;
- use the mock/stub target class when it is statically available so common method names alone do not create noisy clusters;
- ignore bare `createMock()` / `createStub()` declarations and setup that exists only in `tearDown()`;
- require the same canonical shape in at least three distinct files;
- keep `php.mock-heavy-tests-without-assertions` unchanged; it answers a different single-file question.

SLOP-13 is not a prerequisite for this slice. Any remaining structure-rule prerequisite must justify itself independently.
