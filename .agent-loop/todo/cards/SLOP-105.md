# SLOP-105: agent-loop: revising an under-scoped Contract mid-run costs the Run

- **Ticket:** SLOP-105
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** agent-loop-workflow
- **Created:** 2026-08-16T00:43:51+00:00
- **Updated:** 2026-08-16T00:43:58+00:00
- **Summary:** workflow report detects scope drift but workflow plan refuses to revise while the Session is active, so the only fix is dropping the Run and re-recording every validation observation.
- **Format version:** 1

## Agent Task Brief
Durable evidence: .agent-loop/learning/findings/validated/. Reproduced while dogfooding the loop on SLOP-1 in this repository. See docs/agent-loop-workflow.md for the summary and the matching finding id.
