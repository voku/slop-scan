# SLOP-104: agent-loop: board namespace uses a second option grammar and leaks an uncaught ValidationException

- **Ticket:** SLOP-104
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** agent-loop-workflow
- **Created:** 2026-08-16T00:43:51+00:00
- **Updated:** 2026-08-16T00:43:58+00:00
- **Summary:** workflow accepts --by VALUE, board requires --by=VALUE and answers the space form with a PHP fatal error and stack trace.
- **Format version:** 1

## Agent Task Brief
Durable evidence: .agent-loop/learning/findings/validated/. Reproduced while dogfooding the loop on SLOP-1 in this repository. See docs/agent-loop-workflow.md for the summary and the matching finding id.
