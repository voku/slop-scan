# Development

Run local validation:

```bash
composer validate --strict
composer run lint
composer run analyse
composer run test
composer run scan:self
composer run phar:build
```

Run mutation testing with Infection and PHPStan-backed escaped-mutant checks:

```bash
composer run mutate
```

The repository dogfoods `slop-scan` in CI by scanning the whole checkout directly, without a committed baseline file, so pull requests must keep the repository clean enough to pass the same heuristics it ships.

The implementation lives in PSR-4 class files under `src/`, organized by responsibility (for example `Contract/`, `Fact/`, `Model/`, `Reporter/`, `Rule/`, `Runtime/`, and `Support/`); tests live in `tests/`.
