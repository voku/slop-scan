# SLOP-4: Adapt upstream structure.barrel-density as php.reexport-barrel

- **Ticket:** SLOP-4
- **Lane:** BACKLOG
- **Status:** Selected
- **Domain:** rules
- **Created:** 2026-08-16T00:21:58+00:00
- **Updated:** 2026-08-16T00:22:24+00:00
- **Summary:** Flag PHP files whose top-level statements are only class_alias/require re-exports of other modules.
- **Next:** Confirm a PHP barrel analog is worth a rule before implementing; record the decision on the card.
- **Validation:** composer run test && composer run analyse && composer run scan:self
- **Priority:** 4
- **Wave:** 2
- **Format version:** 1

## Agent Task Brief
Upstream: src/rules/barrel-density (family structure, severity medium, file scope, requires file.exportSummary). Upstream reports a file when every top-level statement is a re-export and there are at least two of them. PHP has no re-export syntax, so the analog must be chosen deliberately: files whose top-level statements are only class_alias() calls, or only require/include of sibling files, or a class whose every method is a static forward to another class. Highest risk of being a poor port; upstream signal rank was #8 of 11 and it fired on 5/5 mature OSS repos, so a naive port would be noisy.
