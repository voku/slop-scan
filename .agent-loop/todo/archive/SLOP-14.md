# SLOP-14: Make rule metadata part of the rule contract instead of a parallel catalog

- **Ticket:** SLOP-14
- **Lane:** VERIFY
- **Status:** Done
- **Domain:** engine
- **Created:** 2026-08-16T14:14:13+00:00
- **Updated:** 2026-08-18T18:04:14+00:00
- **Summary:** A rule can be registered and emit findings with no FindingMetadataCatalog entry and nothing reports it; php.magic-numbers has been missing one since before this backlog existed.
- **Next:** Landed as its own change: both entries added plus a completeness invariant.
- **Validation:** composer run test && composer run analyse
- **Priority:** 7
- **Wave:** 5
- **Format version:** 1

## Agent Task Brief
DefaultRegistry and src/Support/FindingMetadataCatalog.php are joined only at read time, so a registered rule with no entry is silent. Enumerating the registry against the catalog reports 23 entries and two registered rules missing: php.generic-status-envelopes (shipped that way under SLOP-1, added by PR #34 as a side effect) and php.magic-numbers, which predates this backlog. Structural fix: make why/suggestedAction/confidence part of the RulePlugin contract so a rule cannot exist without them. Cheap fix: a test asserting every registered rule has an entry. Either way this must be its own change, because the invariant fails on every prior omission the moment it is added. Durable evidence: .agent-loop/learning/findings/validated/finding.2026-08-16.26b01a.json.
