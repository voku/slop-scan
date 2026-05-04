# AGENTS.md

`slop-scan` is a deterministic PHP CLI for explainable slop heuristics on PHP repositories. It is not an authorship detector.

## Start here

- `README.md` for product behavior and CLI expectations.
- `src/` for the PHP implementation, organized into focused directories for contracts, facts, models, reporters, rules, runtime state, and shared support code.
- `tests/PhpCliTest.php` for behavioral expectations.
- `slop-scan.config.json` for the repository self-scan config.

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

## Validation

- `composer validate --strict`
- `composer run lint`
- `composer run analyse`
- `composer run test`
- `composer run scan:self`
- `composer run phar:build`

Run the validation suite in a PHP 8.4-compatible environment. If the host PHP is older than the vendor platform requirement, use the project container or another PHP 8.4+ container.
