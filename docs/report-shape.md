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

## Baseline files

Baseline files are smaller than full JSON scan reports. They contain:

- `metadata`
- `summary.findingCount`
- `findings`

That keeps baseline adoption practical for existing repositories: commit the current findings once, then let CI fail only on newly introduced fingerprints.
