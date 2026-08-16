# SLOP-7: Port the upstream match-kind classification into php.generic-status-envelopes

- **Ticket:** SLOP-7
- **Lane:** VERIFY
- **Status:** Done
- **Domain:** rules
- **Created:** 2026-08-16T08:07:29+00:00
- **Updated:** 2026-08-16T14:05:25+00:00
- **Summary:** Upstream classifies each envelope as returned / json-response / assigned by inspecting the parent node; the PHP port reports which keys matched but not where the envelope goes.
- **Next:** Implemented and validated in PR #34.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 7
- **Wave:** 3
- **Format version:** 1

## Agent Task Brief
Upstream src/rules/generic-status-envelopes/index.ts summarizeEnvelope() inspects node.parent and tags each match returned-, json- (a .json(...) call, i.e. an HTTP response boundary) or assigned-generic-status-envelope, then emits it as 'line N: <kind>'. The PHP port emits status=<key>:<bool> and payload=<key> and drops the kind entirely. PHP analogs: Stmt\Return_, an assignment, and a response-builder call such as $response->json(...) or new JsonResponse(...). An envelope crossing an HTTP boundary is a different review judgement from one assigned to a local, so this is lost signal rather than a deliberate deviation.
