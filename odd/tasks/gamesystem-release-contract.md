# Feature: gamesystem-release-contract

- **Locator:** `odd/tasks/gamesystem-release-contract.md` · Engram topic `odd/gamesystem-release-contract/tasks`
- **Issue:** #16 · **Branch:** `feat/gamesystem-release-contract` (branch point `28a9e80` on `main`)
- **Delivery strategy:** **single-pr** (user choice, size exception) · merge commit
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `randomness-oracles`, delivered in PR #15 (closes #14)

## Objective

Define the Published Language between Studio and Play: a versioned JSON contract for an immutable GameSystem release. Studio imports a release file, validates it and publishes it as the next immutable version. Play reads releases only through an anti-corruption layer that translates them into Play's own snapshot model. Two presets, "Free journal" and "Mythic-style", ship as release files with oracles only. ADR 0013 records that Studio is a core context.

## Scope

- **In:** JSON Schema `backend/contracts/gamesystem-release/v1.schema.json` + contract doc `docs/contracts/gamesystem-release.md`; Studio domain (`ReleaseContent` validation, `GameSystemRelease` aggregate, repository port); publish command + handler, published-release query and view (Studio Application); Doctrine persistence (JSONB) + migration; console command `app:gamesystem:publish`; Play ACL (port, Studio-backed adapter, translator into `GameSystemSnapshot`); presets + `make presets`; ADR 0013; context-map and glossary updates.
- **Out:** HTTP endpoints for releases (feature 5 consumes them); Campaigns and pinning a snapshot in Play's storage (feature 5); Studio drafts and UI (feature 14); flow presentation and running (features 6–7); full Mythic preset with random events and scene checks (feature 9); sheet and check content (later features); campaign upgrade path.

## Constraints

- Dependency rule and PHPat: Studio/Play `Domain/` framework-free; they may use `Randomness\Domain`. Play reaches Studio **only** through `App\Studio\Application\*` (a query and its view); never Studio Domain or Infrastructure.
- ADR 0007: release content is JSONB; identity, key, version and timestamps are relational columns. Content is validated in the domain, not in the database.
- ADR 0010: releases are immutable; publishing never edits an existing release.
- Oracle definitions reuse the Randomness shapes and validators (`OracleTableSet::fromArray`, `LikelihoodOracle::fromArray`), no duplicated oracle rules.
- No JSON-schema library at runtime. `opis/json-schema` is a **dev** dependency used only to test that the schema file, fixtures and presets agree with the domain validation.
- Presets contain only original text; no Mythic GME chart text or meaning words are copied.
- Glossary terms: `GameSystem`, `GameSystem release`, `Published Language`, `Anti-corruption layer`, `NarrativeFlow`, `Flow step`, `Flow preset`, `Oracle`.

## Rules

### Release file (schema version 1)

```json
{
  "schemaVersion": 1,
  "gameSystem": {"key": "free-journal", "name": "Free journal", "description": "Play any setting with a yes/no oracle and idea tables."},
  "oracles": {
    "tables": [{"key": "action", "name": "Action", "entries": [{"text": "Seek"}, {"text": "Guard"}]}],
    "likelihood": [{"key": "yes-no", "name": "Yes/no question", "sides": 100,
                    "levels": [{"key": "likely", "label": "Likely", "target": 65}]}]
  },
  "flow": {"steps": [{"key": "set-scene", "title": "Set the scene", "prompt": "Where are you and what do you want?"}]},
  "sheet": {},
  "checks": []
}
```

- Top level: exactly `schemaVersion`, `gameSystem`, `oracles`, `flow`, `sheet`, `checks`; unknown properties are invalid (here and in every object the contract defines).
- `schemaVersion` must be `1`; any other value fails with "unsupported schema version".
- `gameSystem.key`: the oracle key rule (`^[a-z0-9-]{1,64}$`); `name` 1–100 chars; `description` optional, ≤ 2000 chars.
- `oracles.tables`: Randomness `OracleTableDefinition` shapes, validated as one `OracleTableSet` (nesting, cycles, limits). May be empty.
- `oracles.likelihood`: each item is `key` + `name` (≤ 500) + a Randomness `LikelihoodOracleDefinition` (`sides`, `levels`, `chaos?`, `exceptionalPercent?`), validated by `LikelihoodOracle::fromArray`. May be empty. At most 20.
- Oracle keys are unique across tables **and** likelihood oracles (one namespace, so a flow step can later name any oracle).
- `flow.steps`: may be empty; each step has `key` (key rule, unique in the flow), `title` (1–100), `prompt` optional (≤ 2000). At most 100 steps. The step shape is provisional and will grow in `flow-presentation-spike` under a new schema version.
- `sheet` must be `{}` and `checks` must be `[]` in version 1 (reserved; non-empty fails with "not supported in schema version 1").
- Invalid content fails with one `InvalidReleaseContent` domain error whose message names the path (e.g. `oracles.tables[2]: …`).

### Publishing

- `PublishGameSystemRelease(releaseId, content, onlyIfChanged)`: validates the content; the version is the latest version of that `gameSystem.key` + 1 (first is 1). The release stores id, key, version, schema version, normalized content, content hash (sha256 of the canonical normalized JSON) and `publishedAt`.
- Releases are immutable: no update path exists; (key, version) is unique.
- `onlyIfChanged`: when the latest release of that key has the same content hash, nothing is published (idempotent preset seeding).
- Console: `bin/console app:gamesystem:publish <file> [--if-changed]` reads a JSON file and prints `Published <key> v<N>`, `Unchanged <key> (v<N>)`, or the validation error (exit 1). Malformed JSON or a missing file → error, exit 1.
- `GetPublishedRelease(key, ?version)` → `PublishedReleaseView {gameSystemKey, version, schemaVersion, publishedAt, content}` (latest when version is null); unknown → `PublishedReleaseNotFound`. This view **is** the Published Language Play consumes.

### Play anti-corruption layer

- Play port `PublishedGameSystemReleases` (Play Application) with `get(key, ?version): GameSystemSnapshot`; adapter in Play Infrastructure calls Studio's `GetPublishedRelease` through the query bus and translates the view.
- `GameSystemSnapshot` (Play Domain): game system key, name, release version, `OracleTableSet`, likelihood oracles by key (name + `LikelihoodOracle`), flow steps (key, title, prompt). No Studio types leak into it.
- The translator supports schema version 1 only; an unknown version fails with a clear Play error. A missing release → Play's own not-found error.
- Storing the snapshot with a Campaign is feature 5.

### Presets

- `backend/presets/free-journal.json`: a generic yes/no likelihood oracle (5 levels, no chaos) and a few original spark tables (e.g. action, theme, descriptor); empty flow.
- `backend/presets/mythic-style.json`: a likelihood oracle with chaos factor (1–9, neutral 5) and exceptional results, plus original action/subject meaning tables; empty flow.
- `make presets` publishes both with `--if-changed`.

## Forecast

~2,300 authored lines: T1 ~350 (docs/schema), T2 ~650, T3 ~650, T4 ~400, T5 ~300. Single PR by user choice.

## Tasks

| ID | Task | Route | Status | Commit |
|---|---|---|---|---|
| T1 | ADR 0013 (Studio is core) + README index; context map (Studio Core, open question resolved, Published Language section); glossary (`Preset` / release terms); contract doc `docs/contracts/gamesystem-release.md`; JSON Schema `backend/contracts/gamesystem-release/v1.schema.json` | delegated writer (multi-file) | [x] | see Progress |
| T2 | Studio Domain: `ReleaseContent::fromArray` (all rules above, reusing Randomness validators), normalized array + hash, `GameSystemRelease` aggregate, repository port, errors; unit tests; dev dep `opis/json-schema` + test that valid/invalid fixtures agree with the schema file | delegated writer (multi-file) | [ ] | |
| T3 | Studio Application + Infrastructure: `PublishGameSystemRelease` command/handler, `GetPublishedRelease` query + view, Doctrine XML mapping (JSONB) + repository + migration, `app:gamesystem:publish` console; integration tests; Behat `studio` suite (publish, version increment, unchanged, invalid) | delegated writer (multi-file) | [ ] | |
| T4 | Play ACL: `GameSystemSnapshot` (Domain), port `PublishedGameSystemReleases` + Studio-backed adapter + translator; unit tests for translation and errors; integration test publish → snapshot | delegated writer (multi-file) | [ ] | |
| T5 | Presets `free-journal.json`, `mythic-style.json`; `make presets`; tests: each preset conforms to the schema, publishes, and snapshots in Play with resolvable oracles | delegated writer (multi-file) | [ ] | |

## Acceptance criteria

- A valid release file publishes as v1, then v2; `--if-changed` with identical content publishes nothing; an existing release is never modified.
- Each rule above has a failing case with a path-naming error; the console exits 1 with that message.
- The schema file accepts the valid fixtures and both presets and rejects the structural invalid fixtures.
- Play gets a `GameSystemSnapshot` for a published key (latest or a given version) whose oracles resolve with Randomness; PHPat stays green (Play uses only Studio Application).
- Both presets publish via `make presets` and their oracles resolve in Play's snapshot.
- ADR 0013 accepted; context map shows Studio as Core and the open question resolved.

## Checks

`make qa`, `make test` (+ `make e2e` at closure to confirm nothing regressed).

## Progress / Evidence

- **Exploration:** read-only explorer. Studio/Play contexts empty; Doctrine mapping dirs and PHPat entries registered; Randomness exposes `OracleTableSet::fromArray` / `LikelihoodOracle::fromArray`; no JSON-schema library; next ADR 0013; only console example `Identity/Infrastructure/Console/CreateUserConsoleCommand.php`.
- **T1**: delegated writer (multi-file trigger). ADR 0013, README index, context map (Studio Core, Published Language subsection, open question resolved), glossary (`Preset`, `Schema version`, `GameSystemSnapshot`), `docs/contracts/gamesystem-release.md`, `backend/contracts/gamesystem-release/v1.schema.json` (draft 2020-12). Parent aligned `docs/vision.md` success criterion with ADR 0013. Passive docs: no RED; checks: `json.tool` exit 0; writer validated both examples against the schema (python jsonschema, 0 errors) and links resolve. Accepted refinement: optional fields (`description`, `prompt`, Randomness optionals) may be omitted **or `null`** (Randomness reads `?? null`); T2 must match. Randomness errors name table/entry, not paths: T2 prefixes paths.
- **Decisions (user):** Studio is **core**, both `SOLO_PLAYER` and `GAME_MANAGER` UX are first class; game flows must be flexible enough that specific systems may later need new code (analyzed when needed). Issue #16 created. Single PR.

## Reviews

## Next step

T2.
