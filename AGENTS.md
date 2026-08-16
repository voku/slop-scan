# AGENTS.md

`slop-scan` is a deterministic PHP CLI for explainable slop heuristics on PHP repositories. It is not an authorship detector.

## Start here

- `README.md` for product behavior and CLI expectations.
- `.ai/skills/manifest.yaml` for vendor-neutral, repo-owned agent skills shared across Copilot, Codex, Gemini, and similar tools.
- `src/` for the PHP implementation, organized into focused directories for contracts, facts, models, reporters, rules, runtime state, and shared support code.
- `tests/PhpCliTest.php` for behavioral expectations.
- `slop-scan.config.json` for the repository self-scan config.
- `docs/agent-loop-workflow.md` for the governed task loop and the Kanban board under `.agent-loop/`.
- `docs/upstream-parity.md` for which upstream checks are ported and which are still open.

Use `AGENTS.md` for repository-wide context and the `.ai/skills/` files for task-specific command recipes.

## Mental model

- Language plugins decide which files are in scope.
- Fact providers compute reusable signals at `file`, `directory`, or `repo` scope.
- Rules consume facts and emit findings with evidence, severity, score, locations, and delta identity.
- Reporters render the final analysis as text, lint output, or JSON.
- Config tunes built-ins through JSON-compatible ignores and rule config.

## Working rules

- Preserve determinism, stable ordering, and explainable evidence.
- Prefer adding/extending facts and rules over special-casing the analyzer.
- Keep file/directory/repo scopes in mind.
- Do not tune heuristics to a single fixture or repository.
- Adding a rule usually touches `src/Rule/`, `src/Fact/`, `src/DefaultRegistry.php` and the `php.structure` schema version in `src/Support/ScanCache.php`. Bump that version whenever the provider gains or drops a fact, or a stale cache will serve findings a missing fact cannot support.
- Pick work off the board (`composer run agent-loop:board`) and plan it with the full file set in `--scope`; a Contract cannot be revised while its Session is active.

## Validation

- `composer validate --strict`
- `composer run lint`
- `composer run analyse`
- `composer run test`
- `composer run scan:self`
- `composer run phar:build`
- `composer run agent-loop:verify` when the board or the learning store changed

Run the validation suite in a PHP 8.4-compatible environment. If the host PHP is older than the vendor platform requirement, use the project container or another PHP 8.4+ container.
