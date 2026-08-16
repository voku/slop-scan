# SLOP-2: Port upstream types.generic-record-casts as php.generic-array-casts

- **Ticket:** SLOP-2
- **Lane:** VERIFY
- **Status:** Done
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-16T14:05:24+00:00
- **Summary:** Flag json_decode(..., true) and (array) casts landing in vague bag variables such as $data, $payload, $parsed.
- **Next:** Implemented and validated in PR #34.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 2
- **Wave:** 1
- **Format version:** 1

## Agent Task Brief
Upstream: src/rules/generic-record-casts (family types, severity strong, file scope). Upstream flags 'as Record<string, unknown>' casts landing in bag variables (parsed, payload, body, data, result, config), with extra detail when the source is JSON.parse. PHP adaptation: json_decode($raw, true) and (array) casts assigned into the same vague variable names, plus @var array<string, mixed> annotations on them. Overlaps partially with php.type-escape-hotspots, which counts mixed/cast density per file rather than naming the bag variable.
