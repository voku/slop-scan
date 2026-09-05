# SLOP-108: Nothing checks that a merged rule matches the card that authorised it

- **Ticket:** SLOP-108
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** agent-loop-workflow
- **Created:** 2026-08-18T18:05:49+00:00
- **Updated:** 2026-08-18T18:05:49+00:00
- **Summary:** php.duplicate-mock-setup merged with its contract still candidate on an orphaned branch, its card still in BACKLOG, and no docs/rules.md entry - every gate green throughout.
- **Next:** Start with the docs check; it is the one with an unambiguous pass/fail.
- **Validation:** composer run test && composer run agent-loop:verify
- **Priority:** 8
- **Wave:** 5
- **Format version:** 1

## Agent Task Brief
Durable evidence: .agent-loop/learning/findings/validated/finding.2026-08-18.341ee9.json. Two concrete guards: (1) fail when a rule id registered in DefaultRegistry has no row in docs/rules.md, mirroring the FindingMetadataCatalog invariant added under SLOP-14; (2) fail when a card is in BACKLOG/READY while paths named in its own brief appear in the merged diff. Also decide where governed contracts live: a squash-merge deletes the commits a stacked contract was anchored to, so contract state must land on the default branch with the change it governs.
