# SLOP-13: Add the export/re-export fact behind a possible php.reexport-barrel rule

- **Ticket:** SLOP-13
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** facts
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-18T18:00:00+00:00
- **Summary:** Upstream exports.ts has no PHP counterpart. This is now scoped to SLOP-4 only; the test-side facts it also claimed turned out not to be needed.
- **Next:** Do not start before SLOP-4 decides whether a PHP barrel analogue is worth a rule at all.
- **Validation:** composer run test && composer run analyse
- **Priority:** 6
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
Originally filed as a generic fact-parity prerequisite covering exports.ts, test-mock-setups.ts and test-duplication.ts, and asserted to block SLOP-3 and SLOP-4. That was wrong for the test side: SLOP-3 shipped with no new provider, adding file.testMockSetups to the existing php.structure provider and repo.duplicateMockSetups to the existing FunctionDuplicationFactProvider. Only the export/re-export fact remains genuinely absent, and it is only worth building if SLOP-4 first decides a PHP barrel analogue carries real review signal. Build it with that rule, on an existing fact owner, rather than as a standalone parity layer.
