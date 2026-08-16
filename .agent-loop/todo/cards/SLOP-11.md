# SLOP-11: Give rules a declared delta strategy instead of one global identity scheme

- **Ticket:** SLOP-11
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** engine
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-16T08:08:08+00:00
- **Summary:** Upstream rule-delta.ts offers byPath, byLocations and a semantic deltaKeys escape hatch, chosen per rule; the PHP fork derives one identity for every finding.
- **Next:** Check the existing baseline format stays compatible before changing identity derivation.
- **Validation:** composer run test && composer run analyse
- **Priority:** 4
- **Wave:** 5
- **Format version:** 1

## Agent Task Brief
Upstream src/rule-delta.ts exposes delta.byPath() and delta.byLocations(), plus a semantic deltaKeys escape hatch for clustered findings, and defaults per finding to byLocations when locations were reported and byPath otherwise. Each RulePlugin declares its own strategy. The PHP fork derives one identity for every finding through Delta::identityFor(). This matters for php.generic-status-envelopes specifically: per-site findings only behave correctly under baselines because identity is location-based, and the fork gets that implicitly rather than by declaration, so a future rule that should match per path has no way to say so.
