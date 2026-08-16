# SLOP-9: Port the upstream rule-signal benchmark harness

- **Ticket:** SLOP-9
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** engine
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-16T08:08:08+00:00
- **Summary:** Runs each rule in isolation against a pinned AI-vs-mature-OSS cohort and computes AUROC per normalized metric; this is what makes 'do not tune heuristics to one repository' checkable.
- **Next:** Scope this to the rule-signal benchmark only; the full history/report pipeline is a separate decision.
- **Validation:** composer run test && composer run analyse
- **Priority:** 2
- **Wave:** 4
- **Format version:** 1

## Agent Task Brief
Upstream src/benchmarks/ (11 files) plus scripts/benchmark-{fetch,scan,report,history,rule-signals}.ts. rule-signal.ts builds an isolated Registry per rule by walking fact dependencies down to a BASE_FACT_IDS root set, runs it against a pinned AI-vs-mature-OSS cohort, and computes AUROC for each normalized metric (score/findings per file, per kloc, per function), yielding signalScore, bestMetric and per-cohort hit rates. Every 'signal rank #2 of 9, hit rate 5/6 AI repos vs 2/5 OSS' line in the upstream rule docs comes from here. AGENTS.md tells contributors not to tune heuristics to a single repository; this is the machinery that makes that claim checkable, and the fork has no equivalent. The SLOP-1..SLOP-6 rankings were taken from upstream's numbers and cannot currently be reproduced here.
