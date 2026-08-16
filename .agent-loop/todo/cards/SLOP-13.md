# SLOP-13: Add the fact-layer prerequisites for the unported structure and test rules

- **Ticket:** SLOP-13
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** facts
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-16T08:08:08+00:00
- **Summary:** Upstream exports.ts, test-mock-setups.ts and test-duplication.ts have no PHP counterpart; SLOP-3 and SLOP-4 cannot start until the facts exist.
- **Next:** Blocks SLOP-3 and SLOP-4; do this before either.
- **Validation:** composer run test && composer run analyse
- **Priority:** 6
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
Upstream facts with no PHP counterpart: exports.ts (file.exportSummary, required by structure.barrel-density), test-mock-setups.ts (repo.testMockDuplication, required by tests.duplicate-mock-setup) and test-duplication.ts. SLOP-3 and SLOP-4 are rule cards that cannot start until the facts exist, and both are repo-scope facts rather than file-scope, so they also need a fact provider at that scope. Doing this first keeps SLOP-3/SLOP-4 as rule work instead of hiding fact-layer design inside them.
