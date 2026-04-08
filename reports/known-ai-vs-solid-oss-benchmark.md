# Pinned benchmark: Known AI repos vs older solid OSS repos

Date: 2026-04-08
Analyzer version: 0.1.2
Config mode: default

## Goal

Compare a cohort of known AI-generated JavaScript/TypeScript repos against older, well-regarded OSS repos using pinned commit SHAs and normalized analyzer metrics.

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

Blended score = geometric mean of the six normalized-metric ratios versus the mature OSS cohort medians, then rescaled so the mature OSS cohort median is 1.00. Higher means a repo is consistently noisier across the benchmark dimensions.

## AI cohort

| Repo | Ref | Age | Stars | Blended | Files | Logical LOC | Functions | Score/file | Score/KLOC | Score/function | Findings/file | Findings/KLOC | Findings/function |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| [garrytan/gstack](https://github.com/garrytan/gstack) | `6cc094c` | 0.1y | 65613 | **5.24** | 176 | 18958 | 832 | 2.12 | 19.67 | 0.45 | 0.45 | 4.17 | 0.09 |
| [redwoodjs/agent-ci](https://github.com/redwoodjs/agent-ci) | `4de00d6` | 0.2y | 120 | **3.88** | 94 | 8474 | 220 | 1.05 | 11.64 | 0.45 | 0.28 | 3.07 | 0.12 |
| [jiayun/DevWorkbench](https://github.com/jiayun/DevWorkbench) | `ea50862` | 0.8y | 17 | **3.77** | 32 | 2986 | 147 | 1.00 | 10.76 | 0.22 | 0.44 | 4.69 | 0.10 |
| [robinebers/openusage](https://github.com/robinebers/openusage) | `857f537` | 0.2y | 1715 | **3.51** | 139 | 22270 | 491 | 1.35 | 8.46 | 0.38 | 0.34 | 2.11 | 0.10 |
| [openclaw/openclaw](https://github.com/openclaw/openclaw) | `44cf747` | 0.4y | 350232 | **3.49** | 10580 | 1037965 | 40714 | 1.08 | 11.04 | 0.28 | 0.32 | 3.26 | 0.08 |
| [emdash-cms/emdash](https://github.com/emdash-cms/emdash) | `dbaf8c6` | 0.0y | 7842 | **2.48** | 1072 | 120432 | 3513 | 0.76 | 6.76 | 0.23 | 0.23 | 2.02 | 0.07 |
| [FullAgent/fulling](https://github.com/FullAgent/fulling) | `d95060f` | 0.5y | 2413 | **2.32** | 219 | 12154 | 574 | 0.52 | 9.41 | 0.20 | 0.16 | 2.80 | 0.06 |
| [cloudflare/vinext](https://github.com/cloudflare/vinext) | `28980b0` | 0.1y | 7709 | **2.23** | 1129 | 59523 | 2917 | 0.50 | 9.43 | 0.19 | 0.14 | 2.72 | 0.06 |
| [modem-dev/hunk](https://github.com/modem-dev/hunk) | `b37663f` | 0.1y | 352 | **1.25** | 166 | 13564 | 752 | 0.38 | 4.71 | 0.08 | 0.11 | 1.40 | 0.03 |

## Mature OSS cohort

| Repo | Ref | Age | Stars | Blended | Files | Logical LOC | Functions | Score/file | Score/KLOC | Score/function | Findings/file | Findings/KLOC | Findings/function |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| [vitejs/vite](https://github.com/vitejs/vite) | `bdc53ab` | 6.0y | 79637 | **1.65** | 1433 | 46593 | 2300 | 0.26 | 8.06 | 0.16 | 0.08 | 2.43 | 0.05 |
| [withastro/astro](https://github.com/withastro/astro) | `2c9bf5e` | 5.1y | 58212 | **1.62** | 2812 | 131236 | 4359 | 0.27 | 5.74 | 0.17 | 0.09 | 1.99 | 0.06 |
| [egoist/tsup](https://github.com/egoist/tsup) | `b906f86` | 6.1y | 11198 | **1.03** | 48 | 2813 | 140 | 0.21 | 3.61 | 0.07 | 0.08 | 1.42 | 0.03 |
| [umami-software/umami](https://github.com/umami-software/umami) | `0a83864` | 5.7y | 36012 | **1.01** | 674 | 24859 | 1209 | 0.15 | 4.17 | 0.09 | 0.06 | 1.61 | 0.03 |
| [sindresorhus/execa](https://github.com/sindresorhus/execa) | `f3a2e84` | 10.3y | 7481 | **0.99** | 581 | 20432 | 1008 | 0.17 | 4.85 | 0.10 | 0.05 | 1.37 | 0.03 |
| [antfu-collective/ni](https://github.com/antfu-collective/ni) | `6d96905` | 5.4y | 8146 | **0.73** | 87 | 2138 | 99 | 0.11 | 4.68 | 0.10 | 0.02 | 0.94 | 0.02 |
| [mikaelbr/node-notifier](https://github.com/mikaelbr/node-notifier) | `b36c237` | 13.3y | 5843 | **0.46** | 24 | 2114 | 42 | 0.08 | 0.90 | 0.05 | 0.04 | 0.47 | 0.02 |
| [vercel/hyper](https://github.com/vercel/hyper) | `2a7bb18` | 9.8y | 44687 | **0.43** | 115 | 65160 | 5356 | 0.60 | 1.05 | 0.01 | 0.15 | 0.26 | 0.00 |

## Cohort medians

| Metric | AI median | Solid median | Ratio |
|---|---:|---:|---:|
| Blended score | **3.49** | **1.00** | **3.49x** |
| Score / file | **1.00** | **0.19** | **5.26x** |
| Score / KLOC | **9.43** | **4.42** | **2.13x** |
| Score / function | **0.23** | **0.09** | **2.52x** |
| Findings / file | **0.28** | **0.07** | **4.00x** |
| Findings / KLOC | **2.80** | **1.40** | **2.00x** |
| Findings / function | **0.08** | **0.03** | **2.95x** |

## Spot-check pairings

| AI repo | Solid repo | Score/file ratio | Score/KLOC ratio | Score/function ratio | Findings/file ratio | Findings/KLOC ratio | Findings/function ratio |
|---|---|---:|---:|---:|---:|---:|---:|
| `devworkbench` | `hyper` | 1.69x | 10.25x | 17.11x | 2.96x | 17.97x | 30.01x |
| `openusage` | `umami` | 8.81x | 2.03x | 4.48x | 5.70x | 1.31x | 2.89x |
| `vinext` | `vite` | 1.90x | 1.17x | 1.18x | 1.82x | 1.12x | 1.13x |

## Top rule families by cohort

### AI cohort
- `tests.duplicate-mock-setup` — 1078 (26.9%)
- `defensive.needless-try-catch` — 939 (23.4%)
- `structure.pass-through-wrappers` — 703 (17.5%)
- `structure.barrel-density` — 458 (11.4%)
- `structure.duplicate-function-signatures` — 362 (9.0%)
- `defensive.async-noise` — 330 (8.2%)

### Mature OSS cohort
- `defensive.needless-try-catch` — 148 (31.8%)
- `structure.pass-through-wrappers` — 93 (20.0%)
- `structure.directory-fanout-hotspot` — 68 (14.6%)
- `structure.duplicate-function-signatures` — 60 (12.9%)
- `structure.barrel-density` — 47 (10.1%)
- `defensive.async-noise` — 32 (6.9%)

## Notes

- This benchmark is intentionally pinned to exact commit SHAs so future reruns can reproduce the same cohort.
- AI provenance in the set may come from README disclosures or user-provided provenance recorded in the manifest.
- The benchmark scanner uses the analyzer's default config for every repo to keep results comparable.
- The analyzer still only scans JS/TS-family files, so non-JS/TS portions of mixed-language repos are out of scope.
