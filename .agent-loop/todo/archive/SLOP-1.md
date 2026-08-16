# SLOP-1: Port upstream api.generic-status-envelopes as php.generic-status-envelopes

- **Ticket:** SLOP-1
- **Lane:** VERIFY
- **Status:** Selected
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-16T00:45:48+00:00
- **Summary:** Flag PHP array literals that pair a boolean success/ok key with a generic message/error/data payload key.
- **Next:** Add GenericStatusEnvelopesRule + fixtures, register it, document it in docs/rules.md.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 1
- **Wave:** 1
- **Format version:** 1

## Agent Task Brief
Upstream: src/rules/generic-status-envelopes (family api, severity strong, file scope, upstream signal rank #2 of 9). Upstream flags object literals combining a boolean status key (success|ok) with a generic payload key (message|error|data|rows|present|artifactId). PHP adaptation: array literals (short and long syntax) returned or assigned where a boolean-valued 'success'/'ok'/'status' key coexists with a generic payload key. Keep the upstream carve-outs: a lone {ok: true} or a domain-named payload key stays quiet.
