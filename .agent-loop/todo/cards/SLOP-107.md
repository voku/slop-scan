# SLOP-107: agent-loop: init doctor reports its own conventions as repository defects, and install-assets writes Claude hooks without a distinct opt-in

- **Ticket:** SLOP-107
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** agent-loop-workflow
- **Created:** 2026-08-16T00:43:51+00:00
- **Updated:** 2026-08-16T00:43:58+00:00
- **Summary:** doctor hardcodes docs/agents/skills and the ci/phpstan script names with no way to persist alternatives; install-assets registers PreToolUse hooks behind a single [IMPORTANT] line.
- **Format version:** 1

## Agent Task Brief
Durable evidence: .agent-loop/learning/findings/validated/. Reproduced while dogfooding the loop on SLOP-1 in this repository. See docs/agent-loop-workflow.md for the summary and the matching finding id.
