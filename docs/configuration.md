# Configuration

`slop-scan` reads JSON config from `slop-scan.config.json` or `repo-slop.config.json` in the scan root.

Scans now reuse unchanged per-file analysis by default through `.slop-scan.cache.json` in the scan root. Use `--cache-file` to override that location.

```json
{
  "ignores": ["**/vendor/**", "**/.git/**"],
  "rules": {
    "php.placeholder-comments": { "enabled": true, "weight": 0.5 },
    "php.directory-fanout-hotspot": { "options": { "fileCount": 12 } }
  },
  "ignoreErrors": [
    "#Found PHP debug-output call#",
    { "identifier": "php.empty-catch", "path": "src/Legacy.php", "count": 1 },
    { "ruleId": "php.placeholder-comments", "paths": ["src/Generated/**"] }
  ],
  "overrides": [
    {
      "path": "src/Generated/**",
      "rules": {
        "php.placeholder-comments": { "enabled": false }
      }
    }
  ]
}
```

`ignoreErrors` follows PHPStan-style matching for intentional false positives:

- a string entry is treated as a regular expression matched against the finding message;
- object entries may combine `message`/`messages`, `identifier`/`identifiers` or `ruleId`/`ruleIds`, and `path`/`paths`;
- `path` and `paths` use the same glob matching as `ignores`;
- `count` limits how many matching findings are suppressed, so additional matches remain visible.

## Inline suppressions

For file-scoped one-off suppressions, add an inline comment on the same line as the code site or directly above it:

```php
var_dump($value); // @slop-scan-ignore php.debug-output (legacy shim)

/* @slop-scan-ignore php.error-obscuring-catch, php.error-swallowing (known legacy boundary) */
catch (Throwable $e) {
    throw new RuntimeException('hidden');
}
```

Inline `@slop-scan-ignore` comments match only by rule identifier. They support one identifier or a comma-separated list, plus an optional parenthesized reason.
