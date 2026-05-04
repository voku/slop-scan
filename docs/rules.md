# Supported files and built-in rules

## Supported files

The PHP implementation scans:

- `.php`
- `.phtml`
- `.inc`

## What the built-in rules check and why

`slop-scan` focuses on explainable, reviewable heuristics. These rules try to catch patterns that often show up in rushed, weakly reviewed, or partially cleaned-up code:

| Rule | What it checks | Why it matters |
| --- | --- | --- |
| `php.empty-catch` | `catch` blocks with no statements | Exceptions disappear silently and make failures harder to debug. |
| `php.exception-wrap-without-previous` | `catch` blocks that create a replacement exception from the caught error but do not chain it as `previous` | Message-only wrapping keeps the wording but loses the original type and stack context. |
| `php.error-obscuring-catch` | `catch` blocks that replace the original failure with a generic exception without keeping the previous error | Replacement exceptions can erase the original type and stack context that explain what really failed. |
| `php.error-swallowing` | `catch` blocks that log/print and continue without `throw` or `return` | Errors are acknowledged but not handled, so broken execution keeps going. |
| `php.blanket-static-analysis-suppressions` | Broad `@phpstan-ignore`, `@psalm-suppress`, and similar comments | Blanket suppressions hide real problems and reduce trust in static analysis. |
| `php.excessive-static-analysis-suppressions` | Files with more suppression comments than the configured threshold | A file full of suppressions often signals design debt or papered-over typing issues. |
| `php.stacked-static-analysis-suppressions` | Back-to-back suppression comments above one code site | Stacked ignores are a strong smell that one line is resisting cleanup. |
| `php.commented-out-code` | Comments that look like disabled code | Dead code in comments adds noise and creates doubt about what is still relevant. |
| `php.catch-default-fallbacks` | `catch` blocks that return empty literals such as `null`, `[]`, `''`, `false`, or `0` | Default fallbacks can silently turn real failures into misleading “success” values. |
| `php.catch-returns-exception-message` | `catch` blocks that return the caught exception message or string form as a normal value | Turning failures into returned error text can blur success and failure paths and leak internal details. |
| `php.debug-output` | Calls like `var_dump()`, `print_r()`, `dd()`, or `ray()` left in source | Debug leftovers usually should not ship in production code. |
| `php.mock-heavy-tests-without-assertions` | Tests that mostly build mocks but do not assert behavior | These tests look busy but often do not protect behavior. |
| `php.misleading-phpdoc-types` | PHPDoc param/return types that either disagree with or merely duplicate native types | Misleading docs undermine trust, while redundant docs add noise without extra type value. |
| `php.placeholder-comments` | Comments such as TODO, FIXME, HACK, placeholder, temporary | These markers often reveal unfinished or intentionally deferred work. |
| `php.pass-through-wrappers` | Functions that mostly forward input to another function | Thin wrappers can indicate unnecessary indirection and generated-looking structure. |
| `php.directory-fanout-hotspot` | Directories with unusually high PHP file counts | Large clusters of files can indicate sprawl and review-unfriendly structure. |
| `php.over-fragmentation` | Directories with many tiny PHP files | Excessively tiny files can make simple behavior harder to follow. |
| `php.duplicate-function-signatures` | Repeated function signatures across the repository | Repetition can point to copy-paste design and missed abstraction opportunities. |
| `php.return-constant-stub` | Functions or methods whose only statement is `return null`, `return []`, `return ''`, `return false`, or `return 0` | Single-constant returns often indicate unimplemented or placeholder logic that was never filled in. |
| `php.placeholder-method-bodies` | Methods in concrete (non-abstract, non-interface) classes with completely empty bodies | Empty concrete methods can signal forgotten implementations or scaffolded-but-unfinished code. |
| `php.clone-cluster` | Functions whose bodies are identical across the repository | Identical bodies beyond the length threshold are stronger evidence of copy-paste than duplicate signatures alone. |
| `php.type-escape-hotspots` | Files with concentrated `mixed` native types and type-cast expressions | A high density of `mixed` signatures and explicit casts signals type friction that is being suppressed rather than addressed. |

The tool is intentionally heuristic: a finding is a prompt for review, not a verdict.
