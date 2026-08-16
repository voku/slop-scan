# Supported files and built-in rules

## Supported files

The PHP implementation scans:

- `.php`
- `.phtml`
- `.inc`

It also scans Markdown docs:

- `.md`
- `.markdown`

## What the built-in rules check and why

`slop-scan` focuses on explainable, reviewable heuristics. These rules try to catch patterns that often show up in rushed, weakly reviewed, or partially cleaned-up code:

| Rule | What it checks | Why it matters |
| --- | --- | --- |
| `php.empty-catch` | `catch` blocks with no statements | Exceptions disappear silently and make failures harder to debug. |
| `php.exception-wrap-without-previous` | `catch` blocks that create a replacement exception from the caught error but do not chain it as `previous` | Message-only wrapping keeps the wording but loses the original type and stack context. |
| `php.error-obscuring-catch` | `catch` blocks that replace the original failure with a generic exception without keeping the previous error | Replacement exceptions can erase the original type and stack context that explain what really failed. |
| `php.error-swallowing` | `catch` blocks that log/print and continue without `throw` or `return` | Errors are acknowledged but not handled, so broken execution keeps going. |
| `php.blanket-static-analysis-suppressions` | Broad `@phpstan-ignore`, `@psalm-suppress`, and similar comments | Blanket suppressions hide real problems and reduce trust in static analysis. |
| `php.excessive-static-analysis-suppressions` | Files with more broad or unexplained suppression comments than the configured threshold | A file full of suppressions often signals design debt or papered-over typing issues, while precise suppressions with identifiers and inline reasons stay quieter. |
| `php.stacked-static-analysis-suppressions` | Back-to-back suppression comments above one code site | Stacked ignores are a strong smell that one line is resisting cleanup. |
| `php.commented-out-code` | Comments that look like disabled code | Dead code in comments adds noise and creates doubt about what is still relevant. |
| `php.catch-default-fallbacks` | `catch` blocks that return empty literals such as `null`, `[]`, `''`, `false`, or `0` | Default fallbacks can silently turn real failures into misleading “success” values. |
| `php.catch-returns-exception-message` | `catch` blocks that return the caught exception message or string form as a normal value | Turning failures into returned error text can blur success and failure paths and leak internal details. |
| `php.generic-status-envelopes` | Array literals that pair a boolean `success`/`ok`/`status` key with a generic payload key such as `message`, `error`, `data`, `rows`, or `result` | Wrapping every operation in a shallow boolean envelope describes transport status instead of domain meaning, and is a common shape in generated service glue. |
| `php.debug-output` | Calls like `var_dump()`, `print_r()`, `dd()`, or `ray()` left in source | Debug leftovers usually should not ship in production code. |
| `php.mock-heavy-tests-without-assertions` | Tests that mostly build mocks but do not assert behavior | These tests look busy but often do not protect behavior. |
| `php.magic-numbers` | Inline numeric literals and numeric strings inside function or method bodies, except configured ignored values like `0` and `1` | Unnamed numbers hide intent and are harder to review or change safely. |
| `php.misleading-phpdoc-types` | PHPDoc `@param`, `@return`, and `@var` types on functions, methods, and properties that either disagree with or merely duplicate native types | Misleading docs undermine trust, while redundant docs add noise without extra type value. Imported aliases are resolved before comparison, so `use Vendor\Payload as Message;` does not read as a mismatch. |
| `php.placeholder-comments` | Comments such as TODO, FIXME, HACK, placeholder, temporary | These markers often reveal unfinished or intentionally deferred work. |
| `php.pass-through-wrappers` | Functions that mostly forward input to another function | Thin wrappers can indicate unnecessary indirection and generated-looking structure. |
| `php.directory-fanout-hotspot` | Directories with unusually high PHP file counts | Large clusters of files can indicate sprawl and review-unfriendly structure. |
| `php.over-fragmentation` | Directories with many tiny PHP files | Excessively tiny files can make simple behavior harder to follow. |
| `php.duplicate-function-signatures` | Repeated function signatures across the repository | Repetition can point to copy-paste design and missed abstraction opportunities. |
| `php.return-constant-stub` | Functions or methods whose only statement is `return null`, `return []`, `return ''`, `return false`, or `return 0` | Single-constant returns often indicate unimplemented or placeholder logic that was never filled in. |
| `php.placeholder-method-bodies` | Methods in concrete (non-abstract, non-interface) classes with completely empty bodies | Empty concrete methods can signal forgotten implementations or scaffolded-but-unfinished code. |
| `php.clone-cluster` | Functions whose bodies are identical across the repository | Identical bodies beyond the length threshold are stronger evidence of copy-paste than duplicate signatures alone. |
| `php.type-escape-hotspots` | Files with concentrated `mixed` native types and type-cast expressions | A high density of `mixed` signatures and explicit casts signals type friction that is being suppressed rather than addressed. |
| `markdown.low-signal` | Markdown files dominated by generic summary/checklist/process scaffolding but lacking concrete file, command, or code anchors | Low-signal Markdown artifacts often restate obvious work without preserving durable repository knowledge. |

For `markdown.low-signal`, repository-specific anchors include inline code, Markdown links, file paths such as `src/Analyzer.php`, and concrete commands such as `composer run test` or `php bin/slop-scan.php scan .`.

The rule is intentionally conservative: checklist-heavy docs should stay quiet when they also include at least two descriptive prose lines or enough concrete repository anchors to explain what a maintainer should actually do next.

For `php.generic-status-envelopes`, the status key must carry a literal `true` or `false`, so `['status' => 'archived', 'message' => $text]` stays quiet. A status key on its own (`['ok' => false]`) and a domain-named payload (`['ok' => true, 'repository' => $repo]`) also stay quiet. Bundled or generated files whose logical line count exceeds the `maxFileLines` option (default `5000`) are skipped.

The tool is intentionally heuristic: a finding is a prompt for review, not a verdict.
