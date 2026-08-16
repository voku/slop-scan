# SLOP-6: Close php.catch-returns-exception-message parity gap with upstream defensive.stringified-unknown-errors

- **Ticket:** SLOP-6
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** rules
- **Created:** 2026-08-16T00:21:59+00:00
- **Updated:** 2026-08-16T00:22:25+00:00
- **Summary:** Upstream also flags getMessage() flattening assigned into variables and result-envelope keys, not only returned directly.
- **Next:** Extend the existing rule rather than adding a new rule ID.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 6
- **Wave:** 3
- **Format version:** 1

## Agent Task Brief
Upstream: src/rules/stringified-unknown-errors (family defensive, severity strong, file scope, upstream signal rank #4 of 9). Upstream flags 'error instanceof Error ? error.message : String(error)' in returns, in assignments into message/error properties, and inside result envelopes. The PHP port (php.catch-returns-exception-message) currently only covers returning the caught exception message or its string form directly. Gap: assignment sites such as $message = $e->getMessage(); and envelope keys such as ['success' => false, 'error' => $e->getMessage()]. Pairs naturally with SLOP-1.
