# Report shape

## JSON scan output

JSON scan output includes:

- `metadata`
- `rootDir`
- `config`
- `summary`
- `files`
- `directories`
- `findings`
- `fileScores`
- `directoryScores`

Each finding includes rule identity, severity, scope, message, evidence, score, locations, path, and `deltaIdentity` occurrence fingerprints.

## TOON scan output

Scan output can also be emitted with `--toon`. It carries the same logical report shape as JSON, but serialized via TOON to reduce token usage in LLM-oriented workflows.

## Baseline files

Baseline files are smaller than full scan reports. They contain:

- `metadata`
- `summary.findingCount`
- `findings`

That keeps baseline adoption practical for existing repositories: commit the current findings once, then let CI fail only on newly introduced fingerprints. Baseline files can be stored as either `.json` or `.toon`, and the format is auto-detected from the file extension when reading and writing.
