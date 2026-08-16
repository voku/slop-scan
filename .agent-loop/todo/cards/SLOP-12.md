# SLOP-12: Give each rule its own explainability document

- **Ticket:** SLOP-12
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** docs
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-16T08:08:08+00:00
- **Summary:** Upstream ships a README per rule with flagged examples, usually-ignored cases, fix guidance and an auto-injected benchmark block; the PHP fork gives each rule one table row.
- **Next:** Watch markdown.low-signal: 25 new rule docs are exactly the shape that rule flags.
- **Validation:** composer run scan:self
- **Priority:** 5
- **Wave:** 5
- **Format version:** 1

## Agent Task Brief
Every upstream rule directory ships a README.md with Family/Severity/Scope/Requires, 'Flagged examples', 'Usually ignored', 'How to fix / do this better', a Scoring section and a benchmark-signal block that src/benchmarks/rule-signal-readme.ts injects automatically. The fork gives each rule one row in docs/rules.md. For a tool whose stated pitch is explainable findings, that is a real difference in what a developer sees when a rule fires. Note the self-referential risk: these documents must carry concrete anchors or markdown.low-signal will flag the fork's own rule docs.
