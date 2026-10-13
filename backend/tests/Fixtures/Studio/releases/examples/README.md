# Flow example releases

The five [flow examples](../../../../../../docs/domain/flow-examples.md) as schema version 2 releases (ADR 0018, decision 26). `ReleaseSchemaAgreementTest` validates each file against the schema and the domain; `ExampleReleasesTest` pins the authoring warnings each one yields. Text is original (fan-content rule, decision 29).

Feature 8 (tags, `pick`, the NPC and Thread effects, `{picked}`, NPC counts) is in every example. Parts that need a later feature are left out: the step stays as a plain `prompt` (or `table`) so the flow still reads well. Add each part to its fixture when its feature lands.

Feature 10 is in `west-marches.json` (a small sheet and `pick of: character`). The other examples keep a prompt for the player's Character; the shipped `night-job` preset (`backend/presets/night-job.json`, example 2 played) is the first to use a `character` step, in its Crew scene.

Feature 8b (fact slot hints, `fillFact`, `{fact:key}`) is in every example that declares fact slots.

| Feature | Adds |
|---|---|
| 10 | Sheet fields and the `character` step (schema version 2, [ADR 0022](../../../../../../docs/adr/0022-character-sheets-in-schema-version-2.md)); Characters, the Session party |

## `vtm-chronicle.json`

Facts: Session Zero fills `city`, `era`, `tone`, `ruling-sect`, `prince` and `ambition`; every session opening refills `desire` and quotes `{fact:ambition}`. The `ambition` and `desire` slots carry hints.

Tags: `leader`, `ally`, `touchstone`, `hunter`, `newcomer` (the `rival` tag of the flow example is not used here). The faction, ally and touchstone events `pick` an NPC by tag and use `{picked}`; the Prince, touchstone, ally and new arrivals are created with `createNpc`, the ambition and the session desire with `createThread`, and `closeThread` runs when the desire is met.

The masquerade uses the fixed-threshold style (`condition masquerade`: up to 7 nothing, above it Inquisition raid) at the start of the world turn, next to the hunters `condition`. The warning follows branches, so each forced Scene Type is checked only against the condition that decides it (`AuthoringWarningsTest` pins both orders).

Warnings: none (Hunters strike sets `hunters` to 0, Inquisition raid sets `masquerade` to 4). The `pick` steps add none.

## `cpr-heist.json`

Facts: `client` (`createNpc` then `fillFact`), `target`, `payout` and `plan` are filled by the Briefing steps; Payday quotes `{fact:payout}`.

Tags: `crew`, `client`, `nomad`. The crew and the client are created with `createNpc`; Chase picks `pick NPC [crew, nomad]` (`found` → "{picked} takes the wheel", `none` → "You take the wheel"). The Scene cast arrives with the flow support of feature 8.

Social, Exploration and `npc-name` are duplicated in the release until library content exists.

Warnings: `flows[0].phases[2].worldTurn[1]`, the forced Firefight does not lower `alarm` (accepted by the example: pressure only grows). The Chase `pick` adds none.

## `mythic-session.json`

Nothing is left out: the release has no fact slots.

Tags: `rival`, `ally`. The first goal is a Thread and who is involved an NPC. The random event entries go straight to "What happens instead?" with `pick NPC [rival]` (`none` → `createNpc`), a stranger's `createNpc`, `pick Thread` for leads, "Close it?" with `closeThread` and `pick NPC [ally]`; only the far-away and odd-sign entries roll the meaning tables.

Warnings: none (no forced scenes; the `pick` steps add none).

## `cpr-campaign-in-acts.json`

Facts: `turf` and `crew-name` in Turf, `fixer` after its `createNpc`, `nemesis` after the grudge, `big-job` in the planning opening and `twist` in Betrayal, whose tip quotes `{fact:nemesis}`.

Tags: `crew`, `rival`, `fixer`, `client`. Recruit and the grudge use `createNpc`, loose ends and the debt to the fixer `createThread`; Betrayal picks the fixer, then the client, then asks the player; the act end adds `condition count NPC [crew]` after the `rep` condition.

The heist types of example 2 are short copies; the Night Market has one price per choice.

Warnings: none (Hit squad sets `heat` to 4, Month's end sets `month` to 0 when the rent is paid). The count condition adds none: only tracker conditions are checked.

## `west-marches.json`

Facts: the founding Town fills `town`; every session opening fills `expedition` (hint "Renewed each session") and the session closing asks "Is {fact:expedition} resolved?".

The session opening ends with `pick of: character` ("Who goes on this expedition?", one to six): the Characters picked join the Session's party and the hook Scene's cast. With no Character yet it completes as none.

Sheet: Class, Level (1–20), Hit points (track), Notes, and Gear and Treasure lists, grouped Adventurer and Pack.

Tags: `roster`, `hireling`. The roster and hirelings are created with `createNpc`, rumors with `createThread`; the session opening picks the expedition Thread (`pick Thread`) and `closeThread` runs when it is resolved.

The founding questions and rumors sit in the founding `phaseOpening`, so the shared Town Scene Type (supplies, hirelings) does not repeat them on every visit.

Warnings: none. Encounter and Combat are forced by table entry effects, which the warning does not check. The `pick` and `createThread` parts add none.
