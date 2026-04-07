# Pinned benchmark: Known AI repos vs older solid OSS repos

Date: 2026-04-07
Analyzer version: 0.1.0
Config mode: default

## Goal

Compare a small cohort of explicitly AI-generated JavaScript/TypeScript repos against older, well-regarded OSS repos using pinned commit SHAs and normalized analyzer metrics.

## Reproduction

```bash
bun run benchmark:fetch
bun run benchmark:scan
bun run benchmark:report
```

Manifest: `benchmarks/sets/known-ai-vs-solid-oss.json`
Snapshot: `benchmarks/results/known-ai-vs-solid-oss.json`
Report: `reports/known-ai-vs-solid-oss-benchmark.md`

The pinned refs below are the exact commits used for the saved snapshot.

## Explicit AI cohort

| Repo | Ref | Age | Stars | Files | Logical LOC | Functions | Score/file | Score/KLOC | Score/function | Findings/file | Findings/KLOC | Findings/function |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `universal-pm` | `2d90bde` | 0.0y | 0 | 18 | 1295 | 149 | 4.93 | 68.49 | 0.60 | 1.28 | 17.76 | 0.15 |
| `voice-notifications` | `8a984b8` | 0.6y | 12 | 5 | 156 | 7 | 1.50 | 48.08 | 1.07 | 0.60 | 19.23 | 0.43 |
| `openusage` | `857f537` | 0.2y | 1715 | 139 | 22270 | 491 | 1.39 | 8.67 | 0.39 | 0.33 | 2.07 | 0.09 |
| `devworkbench` | `ea50862` | 0.8y | 17 | 32 | 2986 | 147 | 0.98 | 10.52 | 0.21 | 0.44 | 4.69 | 0.10 |
| `fulling` | `d95060f` | 0.5y | 2413 | 219 | 12154 | 574 | 0.77 | 13.79 | 0.29 | 0.25 | 4.53 | 0.10 |

## Mature OSS cohort

| Repo | Ref | Age | Stars | Files | Logical LOC | Functions | Score/file | Score/KLOC | Score/function | Findings/file | Findings/KLOC | Findings/function |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| `ni` | `6d96905` | 5.4y | 8146 | 87 | 2138 | 99 | 0.72 | 29.41 | 0.64 | 0.16 | 6.55 | 0.14 |
| `node-notifier` | `b36c237` | 13.3y | 5843 | 24 | 2114 | 42 | 0.20 | 2.32 | 0.12 | 0.08 | 0.95 | 0.05 |
| `tsup` | `b906f86` | 6.0y | 11198 | 48 | 2813 | 140 | 0.22 | 3.72 | 0.07 | 0.08 | 1.42 | 0.03 |
| `execa` | `f3a2e84` | 10.3y | 7481 | 581 | 20432 | 1008 | 0.24 | 6.82 | 0.14 | 0.08 | 2.30 | 0.05 |
| `hyper` | `2a7bb18` | 9.8y | 44687 | 115 | 65160 | 5356 | 0.74 | 1.30 | 0.02 | 0.18 | 0.32 | 0.00 |
| `umami` | `0a83864` | 5.7y | 36012 | 674 | 24859 | 1209 | 0.66 | 17.81 | 0.37 | 0.20 | 5.47 | 0.11 |

## Cohort medians

| Metric | AI median | Solid median | Ratio |
|---|---:|---:|---:|
| Score / file | **1.39** | **0.45** | **3.10x** |
| Score / KLOC | **13.79** | **5.27** | **2.62x** |
| Score / function | **0.39** | **0.13** | **3.09x** |
| Findings / file | **0.44** | **0.12** | **3.58x** |
| Findings / KLOC | **4.69** | **1.86** | **2.52x** |
| Findings / function | **0.10** | **0.05** | **2.03x** |

## Spot-check pairings

| AI repo | Solid repo | Score/file ratio | Score/KLOC ratio | Score/function ratio | Findings/file ratio | Findings/KLOC ratio | Findings/function ratio |
|---|---|---:|---:|---:|---:|---:|---:|
| `universal-pm` | `ni` | 6.82x | 2.33x | 0.94x | 7.94x | 2.71x | 1.09x |
| `voice-notifications` | `node-notifier` | 7.35x | 20.74x | 9.18x | 7.20x | 20.33x | 9.00x |
| `devworkbench` | `hyper` | 1.33x | 8.09x | 13.51x | 2.40x | 14.55x | 24.29x |
| `openusage` | `umami` | 2.12x | 0.49x | 1.07x | 1.64x | 0.38x | 0.83x |

## Top rule families by cohort

### Explicit AI cohort
- `defensive.needless-try-catch` — 50 (35.5%)
- `tests.duplicate-mock-setup` — 29 (20.6%)
- `defensive.async-noise` — 27 (19.1%)
- `structure.directory-fanout-hotspot` — 17 (12.1%)
- `structure.pass-through-wrappers` — 13 (9.2%)
- `structure.barrel-density` — 5 (3.5%)

### Mature OSS cohort
- `defensive.async-noise` — 112 (50.0%)
- `structure.directory-fanout-hotspot` — 36 (16.1%)
- `defensive.needless-try-catch` — 33 (14.7%)
- `structure.pass-through-wrappers` — 21 (9.4%)
- `structure.over-fragmentation` — 15 (6.7%)
- `structure.barrel-density` — 7 (3.1%)

## Notes

- This benchmark is intentionally pinned to exact commit SHAs so future reruns can reproduce the same cohort.
- The benchmark scanner uses the analyzer's default config for every repo to keep results comparable.
- The analyzer still only scans JS/TS-family files, so non-JS/TS portions of mixed-language repos are out of scope.
