# Flow example releases

The five [flow examples](../../../../../../docs/domain/flow-examples.md) as schema version 2 releases (ADR 0018, decision 26). `ReleaseSchemaAgreementTest` validates each file against the schema and the domain; `ExampleReleasesTest` pins the authoring warnings each one yields. Text is original (fan-content rule, decision 29).

Parts that need a later feature are left out: the step stays as a plain `prompt` (or `table`) so the flow still reads well, and the fact slots are declared. Add each part to its fixture when its feature lands.

| Feature | Adds |
|---|---|
| 8 | Tags, `pick` and its `found` / `none` branches, `createNpc`, `createThread`, `closeThread`, `{picked}`, NPC counts in conditions |
| 8b | `fillFact`, fact slot `hint`, `{fact:key}` |
| 10 | Characters, the Session party |

## `vtm-chronicle.json`

| Left out | Feature |
|---|---|
| Tags `leader`, `ally`, `touchstone`, `rival`, `hunter`, `newcomer`; `pick NPC` and `{picked}` in the faction, ally and touchstone events | 8 |
| `createNpc` for the Prince, touchstone, ally and new arrivals; `createThread` for the ambition and the session desire; `closeThread` when the desire is met | 8 |
| `fillFact` for every slot; slot hints "Long-term" / "Short-term, renewed each session"; `{fact:ambition}` in the session opening | 8b |

The masquerade uses the fixed-threshold style (`condition masquerade`: up to 7 nothing, above it Inquisition raid) at the start of the world turn, next to the hunters `condition`. The warning follows branches, so each forced Scene Type is checked only against the condition that decides it (`AuthoringWarningsTest` pins both orders).

Warnings: none (Hunters strike sets `hunters` to 0, Inquisition raid sets `masquerade` to 4).

## `cpr-heist.json`

| Left out | Feature |
|---|---|
| Tags; `createNpc` for the crew and the client; `pick NPC [crew, nomad]` in Chase (now "Who takes the wheel?"); the Scene cast | 8 |
| `fillFact` for client, target, payout and plan; `{fact:payout}` in Payday | 8b |

Social, Exploration and `npc-name` are duplicated in the release until library content exists.

Warnings: `flows[0].phases[2].worldTurn[1]`, the forced Firefight does not lower `alarm` (accepted by the example: pressure only grows).

## `mythic-session.json`

| Left out | Feature |
|---|---|
| Tags `rival`, `ally`; `createThread` for the first goal; `createNpc` for who is involved | 8 |
| Random event branches: `pick NPC [rival]` / `none → createNpc`, a stranger's `createNpc`, `pick Thread` for leads, "Close it?" with `closeThread`, `pick NPC [ally]`. These entries go straight to "What happens instead?"; only the far-away and odd-sign entries roll the meaning tables | 8 |

Warnings: none (no forced scenes).

## `cpr-campaign-in-acts.json`

| Left out | Feature |
|---|---|
| Tags; `createNpc` in Recruit and for the grudge; `createThread` for loose ends and the debt to the fixer; `pick NPC` for the rival crew, the fixer and the client in Betrayal; `{picked}` | 8 |
| The `condition count NPC [crew]` at the act end: the act ends on `rep` alone | 8 |
| `fillFact` for turf, crew name, big job, twist, fixer and nemesis; `{fact:nemesis}` | 8b |

The heist types of example 2 are short copies; the Night Market has one price per choice.

Warnings: none (Hit squad sets `heat` to 4, Month's end sets `month` to 0 when the rent is paid).

## `west-marches.json`

| Left out | Feature |
|---|---|
| Tags; `createNpc` for the roster and hirelings; `createThread` for rumors; `pick Thread` for the expedition; `closeThread` when it is resolved | 8 |
| `fillFact` for town and expedition; slot hint "Renewed each session"; `{fact:expedition}` | 8b |
| The roster as Characters; `pick Characters` for the Session party (now "Who goes on this expedition?") | 10 |

The founding questions and rumors sit in the founding `phaseOpening`, so the shared Town Scene Type (supplies, hirelings) does not repeat them on every visit.

Warnings: none. Encounter and Combat are forced by table entry effects, which the warning does not check.
