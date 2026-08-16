# SLOP-5: Adapt upstream defensive.async-noise as php.await-noise

- **Ticket:** SLOP-5
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** rules
- **Created:** 2026-08-16T00:21:59+00:00
- **Updated:** 2026-08-16T00:22:25+00:00
- **Summary:** Flag redundant await ceremony and trivial async pass-through wrappers in Amp/ReactPHP/Fiber code.
- **Next:** Scope to Amp/ReactPHP/Fiber projects only; skip if the fixture corpus cannot support it.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 5
- **Wave:** 3
- **Format version:** 1

## Agent Task Brief
Upstream: src/rules/async-noise (family defensive, severity medium, file scope). Upstream reports redundant 'return await' around a direct call and trivial async pass-through wrappers with no internal await, exempting edge-facing targets (fetch, axios, prisma, redis). PHP adaptation: Amp/ReactPHP await() wrappers and Fiber::suspend passthroughs. PHP has no language-level async, so this only has meaning for repositories using those libraries; the rule must stay silent everywhere else. Upstream signal rank was #7 of 11, the weakest of the missing checks.
