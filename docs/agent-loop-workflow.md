# The agent-loop workflow in this repository

`voku/agent-loop` is installed as a dev dependency and provides the governed
task loop, the Kanban board, the PHP symbol map, and the durable learning store
used to plan and review changes here. It is additional tooling: nothing in
`slop-scan` itself depends on it, and `composer install --no-dev` does not pull
it in.

## Where state lives

Everything sits below `.agent-loop/`. The split between durable and
regenerable state is deliberate:

| Path | Tracked | What it is |
| --- | --- | --- |
| `.agent-loop/todo/` | yes | the Kanban board and its cards |
| `.agent-loop/tasks/` | yes | task briefs referenced by the cross-package verifier |
| `.agent-loop/contracts/` | yes | approved plans, scope, and acceptance criteria |
| `.agent-loop/learning/` | yes | validated findings and proposals |
| `.agent-loop/map/` | no | generated symbol index and search index |
| `.agent-loop/recall/` | no | compiled per-task briefing artifacts |
| `.agent-loop/runs/`, `sessions/`, `edit/` | no | run-local working memory |
| `.agent-map/` | no | PHPStan cache written by `map build` |

## Commands

Bootstrap once per checkout:

```bash
composer run agent-loop:install
```

That runs `init install-assets --agent=all`, which projects the agent-loop
skills and subagents into `.codex/`, `.claude/`, `.github/` and `.agents/`, and
registers the repository-local Claude hooks in `.claude/settings.json`. Those
projections are tracked so agents can read them without a Composer install; run
the command again after upgrading `voku/agent-loop`.

Build the symbol map **with explicit paths**. An unscoped `map build` also
indexes generated PHP under `build/` and takes about twenty times as long:

```bash
composer run agent-loop:map
```

Check the board:

```bash
vendor/bin/agent-loop board summary
vendor/bin/agent-loop board render --domain=rules
vendor/bin/agent-loop board card show SLOP-1
```

## Running one governed task

```bash
vendor/bin/agent-loop board card claim SLOP-2 --by="$(git config user.name)" --move-to-doing

vendor/bin/agent-loop workflow plan SLOP-2 \
  --by "$(git config user.name)" \
  --file src/Rule/YourRule.php \
  --scope src --scope tests --scope docs \
  --goal "..." \
  --behavior-anchor "PHP source file under scan -> slop-scan finding list (rule id, evidence, score)" \
  --validation "composer run lint" \
  --validation "composer run analyse" \
  --validation "composer run test" \
  --validation "composer run scan:self"

vendor/bin/agent-loop workflow approve SLOP-2 --by "$(git config user.name)"
vendor/bin/agent-loop workflow context SLOP-2
```

Adding a rule almost always touches `src/Fact/`, `src/DefaultRegistry.php` and
`src/Support/ScanCache.php` as well as `src/Rule/`. Put all of it in `--scope`
at plan time: a Contract cannot be revised while its Session is active, so a
scope correction later costs a dropped Run and a re-recorded validation ledger.

After the change, record real results and walk the gates:

```bash
vendor/bin/agent-loop session validation record SLOP-2 \
  --contract-revision <n> --command "composer run test" \
  --status passed --exit-code 0 --duration-ms 0 --by "$(git config user.name)"

vendor/bin/agent-loop review blindspots SLOP-2
vendor/bin/agent-loop review code SLOP-2
vendor/bin/agent-loop workflow report SLOP-2 --changed-file <path> ...
vendor/bin/agent-loop workflow learn SLOP-2 --status findings_recorded --by "..." --reason "..."
vendor/bin/agent-loop verify --task-id=SLOP-2
vendor/bin/agent-loop workflow close SLOP-2 --status done
```

Read `--contract-revision` off `workflow status`; do not assume `1`. Evidence
recorded against a superseded revision does not satisfy the close gate, and the
gate names the command rather than the revision when it refuses.

Note that `board` subcommands require `--option=value`, while `workflow`
subcommands also accept `--option value`.

## The board

The board carries two kinds of card:

- `--domain=rules` — the upstream parity backlog, `SLOP-1` upward. See
  [upstream rule parity](upstream-parity.md).
- `--domain=agent-loop-workflow` — friction found in the workflow itself,
  `SLOP-101` upward, each backed by a validated finding under
  `.agent-loop/learning/findings/validated/`.

The second group exists because dogfooding a workflow only pays off if what
goes wrong is written down where it can be acted on. Read them with:

```bash
vendor/bin/agent-loop board render --domain=agent-loop-workflow
vendor/bin/agent-loop learn validate
```
