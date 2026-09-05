# SLOP-4: Adapt upstream structure.barrel-density as php.reexport-barrel

- **Ticket:** SLOP-4
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-18T18:00:00+00:00
- **Summary:** Flag PHP files whose top-level statements are only class_alias/require re-exports of other modules.
- **Next:** Decide first whether a PHP barrel analogue carries real signal; only then add the export fact, on an existing fact owner.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 4
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
Upstream: src/rules/barrel-density (family structure, severity medium, file scope, requires file.exportSummary). Upstream reports a file when every top-level statement is a re-export and there are at least two. PHP has no re-export syntax, so the analogue must be chosen deliberately: files whose top-level statements are only class_alias() calls, only require/include of sibling files, or a class whose every method is a static forward. Upstream ranked it #8 of 11 by signal and it fired on 5/5 mature OSS repos, so a naive port would mostly be noise; ending with no PHP rule is an acceptable outcome. Not blocked by SLOP-13: SLOP-3 showed a new fact does not need a new provider, so the export fact should be built with this rule if the decision is to proceed.
