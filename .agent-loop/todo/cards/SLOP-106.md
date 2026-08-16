# SLOP-106: agent-map: phpstan/phpstan is a runtime require, making a dist-only binary an install-time SPOF

- **Ticket:** SLOP-106
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** agent-loop-workflow
- **Created:** 2026-08-16T00:43:51+00:00
- **Updated:** 2026-08-16T00:43:58+00:00
- **Summary:** Every agent-loop consumer inherits PHPStan as a hard require; PHPStan publishes no source, so --prefer-source cannot recover when GitHub archive endpoints are unavailable.
- **Format version:** 1

## Agent Task Brief
Durable evidence: .agent-loop/learning/findings/validated/. Reproduced while dogfooding the loop on SLOP-1 in this repository. See docs/agent-loop-workflow.md for the summary and the matching finding id.
