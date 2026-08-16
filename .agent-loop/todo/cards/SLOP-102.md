# SLOP-102: agent-loop: unscoped map build indexes generated PHP and writes 291 MB to .agent-map/

- **Ticket:** SLOP-102
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** agent-loop-workflow
- **Created:** 2026-08-16T00:43:51+00:00
- **Updated:** 2026-08-16T00:43:58+00:00
- **Summary:** map build with no --paths took 3m29s for a 100 MB index and left a PHPStan cache outside .agent-loop/, which pulled 16k files into slop-scan's own self-scan.
- **Format version:** 1

## Agent Task Brief
Durable evidence: .agent-loop/learning/findings/validated/. Reproduced while dogfooding the loop on SLOP-1 in this repository. See docs/agent-loop-workflow.md for the summary and the matching finding id.
