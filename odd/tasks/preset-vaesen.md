# Feature: preset-vaesen

- **Locator:** `odd/tasks/preset-vaesen.md` · Engram topic `odd/preset-vaesen/tasks`
- **Issue:** #294 · **Branch:** `feat/preset-vaesen-2-point-budgets` · **Status:** in progress (slice 2)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B9 (after `rules-dice-pools`, [ADR 0041](../../docs/adr/0041-dice-pools-pushes-and-conditions.md), and `rules-flow-choices`, [ADR 0042](../../docs/adr/0042-flow-choices-check-sets-and-scene-tables.md))

## Objective

The first preset on dice pools: a Vaesen-style mystery game of folk-beings in the 19th-century North, on the Year Zero Engine SRD, authored **in Studio** (as `preset-pbta` did), played for a session, and exported unedited into `backend/presets/`. d6 pools sized by attribute + skill, sixes as successes, pushed rolls that cost a Condition, and an investigation flow. Gaps the session reveals are fixed; UX findings are logged.

Out of scope: VtM's criticals and Hunger dice (B10); items and money (B11); Studio polish beyond the gaps that block this preset.

## Problem and why

- `rules-dice-pools` (B8) built pools, pushes and conditions in every layer, but no preset uses them and no one has authored one end to end in Studio.
- Every earlier preset rolls `dice` + modifier; a pool system tests the check editor, the sandbox, a pending `check` step (Keep / Push) and condition picks on real content.

## Decisions

| Question | Decision |
|---|---|
| License | Free League's Year Zero Engine Free Tabletop License covers the **YZE SRD only**, in print, PDF or a virtual tabletop module, not video games; it excludes Free League's other material and brands and forbids implying affiliation. A preset is a tabletop module in a play aid, so the SRD fits. Vaesen's name, setting and text are not covered (user, 2026-10-26). Version 1.1 adds a generative-AI disclosure, so the notice states it |
| What we build | An original game, **Lanterns of the Mire** (key `lanterns-of-the-mire`), as Percentile Horror did for CoC: our own attribute, skill, Condition and table names and wording; public-domain Nordic folklore only; no "Vaesen" anywhere in the release |
| Authoring route | Studio UI through Claude in Chrome, as a manager would; no API scripts, no JSON edits. The exported file is committed unedited ([ADR 0036](../../docs/adr/0036-export-releases-as-preset-files.md)) |
| Pools | Pool `{attr} + {skill} - {conditions}`, d6, success at 6; a pool never drops below one die (ADR 0041) |
| Push cost | Mark one Condition of the matching kind, the player picks it (`mark` without a value) |
| Fear | Stand Fast (Reason + Heart) is a pool check without a push |
| Skill points (P2) | Built in this feature: a sheet may declare several point budgets, so Lanterns budgets attributes (12) and skills (8, at most 3 each) (user, 2026-10-26) |
| Budget shape (slice 2) | Additive `sheet.pointBudgets`: a list of 1–10 `{key, label, total, fields, min?, max?}`, key and label required; the legacy single `pointBudget` stays valid, declaring both is refused. No schema version 3; every existing content hash is unchanged. Studio drafts hold a list; publishing one budget emits the legacy `pointBudget` (no key), so a one-budget release keeps its shape and hash; two or more emit `pointBudgets` (parent, 2026-10-26) |
| Budgets and fields (slice 2) | A field sits in at most one budget: refused at publish and by Studio draft commands |
| Roll or points (slice 2) | One method per Character, as in ADR 0040. A budget is *rollable* when it covers a field with a dice default; the player picks a method only when some budget is rollable. `roll` rolls the dice fields and skips rollable budgets, but still spends every other budget (Percentile Horror's skill points); `points` spends every budget. `point_budget_exceeded` names the budget's label |
| Studio API and views (slice 2) | Budgets addressed by key (`PUT` / `DELETE …/sheet/point-budgets/{key}`). The single-budget endpoint and view field keep working on a draft with at most one budget until slice 3 moves the UI, then go. Play's pinned release response adds `pointBudgets` beside `pointBudget` for the same window |

## Publisher policy

- License: [Year Zero Engine Free Tabletop License version 1.1](https://freeleaguepublishing.com/wp-content/uploads/2026/03/Year-Zero-Engine-License-Agreement-version-1.1.pdf) (Fria Ligan AB, 2026-03-31), linked from [Free League's Open Game Licenses page](https://freeleaguepublishing.com/community-content/free-tabletop-licenses/) (read in Chrome 2026-10-26; the site refuses scripted fetches). Section 1: the SRD in print, PDF or a virtual tabletop module, not NFTs or video games. Section 2: the SRD only, no other Free League text, art, brands or logos. Section 3: text made with generative AI must be stated. Section 7: the notice and a link to the license. Section 8: no implied endorsement.
- `credits.notice`: "Lanterns of the Mire is an original game; its text was written with the help of generative AI. This game is not affiliated with, sponsored, or endorsed by Fria Ligan AB. The Year Zero Engine System Reference Document is used under Fria Ligan AB's Free Tabletop License." `credits.license` "Year Zero Engine Free Tabletop License 1.1"; `credits.url` the license PDF above.
- No Free League logos or game names; the optional Year Zero Engine logo is not used.

## Preset outline

GameSystem `lanterns-of-the-mire`, "Lanterns of the Mire", description "An original mystery game of folk-beings and lantern light in the 1870s North, built on the Year Zero Engine SRD." Original wording throughout.

- **Sheet:** archetype, drive, dark secret (text); attributes Vigour, Finesse, Reason, Heart (`number` 2..5, default 2); twelve skills (`number` 0..5, default 0): Vigour: Brawl, Endure, Haul; Finesse: Shoot, Sneak, Nimble; Reason: Lore, Inquiry, Alertness; Heart: Persuade, Mend, Insight. Conditions `body` "Bodily harm" (Winded, Bruised, Bleeding) and `mind` "Strain on the mind" (Rattled, Dread, Despair); XP (`number` 0..20); Keepsakes and gear (`list`).
- **Checks (13 pool checks, d6, success at 6):** one per skill, pool `{attribute} + {skill} - {body}` for Vigour and Finesse skills, `- {mind}` for Reason and Heart skills; bands Failure (0), Success (1), Strong success (2+); push "Mark bodily harm" / "Mark strain on the mind" marks the matching Condition, player's pick. Stand Fast: `{reason} + {heart} - {mind}`, no push.
- **Oracles (original tables):** yes/no likelihood; The folk-being (d12: water-horse, forest-wife, drowned one, night-rider, marsh-light, changeling, barrow-dweller, church-grim, hill-folk, mine-keeper, hearth-gnome, sea-wife); What it wants (d8); Where the trouble lies (d8); A clue (d10); Complication (d8).
- **Narrative:** trackers Clues (counter 0..6) and Nightfall (clock 0..6, full: the folk-being strikes); NPC tags Witness, Suspect, Folk-being, Ally; scene types Inquiry, Struggle, Respite, Confrontation. Steps live on the scene types.
- **Flow "A Mystery":** Prologue (once: character; place table; who wrote the letter → NPC; the mystery → Thread); Investigation (loop: Inquiry: intent, clue table, a check set of Reason and Heart skills, Clues +1 on success, Nightfall +1 on failure; Struggle: complication table, a check set of Vigour and Finesse skills, Nightfall +1 on failure; Respite: clear bodily harm or strain on the mind); Unmasking (once, a Confrontation: folk-being and its want tables, Stand Fast marks Dread on failure, a check set Lore / Persuade / Brawl / Shoot marks Bleeding on failure; phase closing: three XP questions with sheet effects, conditions cleared). Clues at 4 tells the player they know enough. The full wording is the content spec the authoring worker used; the exported release is the record.

## Constraints

- Contract unchanged: schema version 2; the exported file is exactly what Studio published.
- Content rule ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decision 29, [ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)): original wording, credits carry the notice.
- Every slice safe on `main` alone.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-vaesen-1-preset` | `feat(presets): preset-vaesen 1/4 lanterns of the mire` | T1–T4 | ~1,900: this doc and roadmap ~250; exported JSON ~1,600 (v1 is 1,580); `PresetsTest` ~100. Over the ceiling because the Studio export is one file: reviewed per commit (T1, T4) if reviewed |
| 2 | `feat/preset-vaesen-2-point-budgets` | `feat(studio): preset-vaesen 2/4 several point budgets` | T5–T7 | ~1,500: ADR ~150; contract, publisher and contract doc ~120; Studio backend ~150 + tests ~250; Play translator, `SheetTemplate`, `CharacterCreation`, responses ~180 + tests ~200; generated OpenAPI and TS ~100; fixtures ~50 |
| 3 | `feat/preset-vaesen-3-budgets-ui` | `feat(studio): preset-vaesen 3/4 point budgets in Studio and Play` | T8–T10 | ~900: slice 2 review fixes; Studio sheet budget list ~250 + tests and story ~200; Play character form one counter per budget ~125 + tests ~100; S27 kind list ~80; Chrome pass |
| 4 | `feat/preset-vaesen-4-skill-budget` | `feat(presets): preset-vaesen 4/4 skill budget and tests` | T11–T13 | ~700: slice 3 review fixes; Lanterns skill budget in Studio, re-export (diff); flow unit test, e2e spec; follow-up issue for the UX findings; closing |

Point budget map (explorer, 2026-10-26): publisher `Studio/Domain/Release/Version2/PointBudget.php`; Studio `GameSystem.php:570-602`, `DraftReferences.php:237-242`, `SaveSheetPointBudget*`, `GameSystemSheetController.php:232-279`; Play translator `GameSystemReleaseTranslator.php:950-982`, `SheetTemplate.php:29,75`, `CharacterCreation.php:64-133`; frontend `studio/sheet/SheetPointBudgetPanel.tsx`, `play/characters/creation.ts`, `CharacterForm.tsx:163-169`. The upgrade planner does not read budgets. Turning `sheet.pointBudget` into an array changes the content hash of every release with a budget (`ReleaseContent.php:18-19`), so the ADR picks an additive property or schema version 3. Open design points for the ADR: a field in two budgets (refuse), roll-or-points per budget or per Character, budget keys in Studio routes and error paths. `docs/contracts/gamesystem-release.md:279` wrongly says Play refuses `pointBudget`.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; roadmap B9 in progress (Vaesen-style original on the YZE SRD) | parent (inline, docs) | [x] | ed44865 |
| T2 | 1 | Author `lanterns-of-the-mire` in Studio from the outline, sandbox-roll a pool and a push, publish v1; log every Studio friction under Studio findings | parent (browser, worker) | [x] | — |
| T3 | 1 | Play a session in Play (browser): Prologue, Inquiry, Struggle, Respite, Unmasking, Aftermath; pushes from the sheet and from a step; fix content in Studio and republish; log Play findings and gaps | parent (browser, worker) | [x] | — |
| T4 | 1 | Export the latest release to `backend/presets/lanterns-of-the-mire.json` unedited; `PresetsTest` (publishes, credits notice, 13 pool checks, push costs, conditions) | delegated writer (slice 1) | [x] | 43ab5a1 |
| T5 | 2 | ADR: several point budgets per sheet (amends ADR 0040): shape, hash and legacy single budget, a field in one budget only, roll-or-points rule, Studio routes; contract doc | delegated writer (Opus, design; preparation trigger: code reading for the design) | [x] | |
| T6 | 2 | Contract, publisher and Studio backend: schema, validation, draft commands, in-use guards, views and API; tests; `make api` | delegated writer (Sonnet) | [x] | |
| T7 | 2 | Play backend: translator reads both shapes, `SheetTemplate` budgets, `CharacterCreation` per budget, pinned release response; tests | delegated writer | [ ] | |
| T8 | 3 | Studio sheet: a list of budgets (add, edit, remove), error paths; tests and story | delegated writer | [ ] | |
| T9 | 3 | Play character form: one counter per budget, submit blocked when any is over; tests | delegated writer | [ ] | |
| T10 | 3 | S27: the effect-kind select shows every kind (no clipped tenth row); test | delegated writer | [ ] | |
| T11 | 4 | Lanterns: skill budget (8, at most 3) in Studio, publish, re-export unedited; `PresetsTest` update | parent (browser) + delegated writer | [ ] | |
| T12 | 4 | Flow unit test (Clues and Nightfall, Nightfall ends the phase, a pushed step marks a Condition, Respite clears) and e2e spec (credits, a pool roll in the journal) | delegated writer | [ ] | |
| T13 | 4 | Wrap-up: follow-up issue for the UX findings (P3, P6, P7, P9, P13, Studio polish); roadmap B9 done; this doc closed | delegated writer | [ ] | |

## Studio findings

Authored 2026-10-26 by a Claude in Chrome worker as `mire-author@ogami.test` (~1 h, ~580 browser actions: sheet ~450, scene types ~350, flow ~250, checks ~230 with Duplicate, oracles ~110 with Paste entries). Release v1 published, validation clean on the first run, no console errors. Simplified in v1: theme `eldritch`; the Strong success band and the "You know enough" condition band shortened to their caps; Respite and the Unmasking XP questions without sheet effects (S17, S20).

| ID | Finding | Severity | Plan |
|---|---|---|---|
| S1 | Checks: band label capped at 100 characters; the error shows only on Create, no live counter | Low | → Studio polish |
| S2 | Sheet: "Declare layout" needs a field first; each group's columns and span are edited in its own dialog | Low | — |
| S5 | Overview: themes none, parchment, neon, noir, eldritch, starship | — | Info |
| S6, S10 | Checks: Duplicate prefills a copy; oracle tables: Paste entries takes a whole table | — | Positive |
| S7 | Checks sandbox: pool of 5d6 rolled, push re-rolled the 4 missed dice and showed the cost "not applied in a test roll" | — | Works |
| S8 | Checks: the push cost field select lists number fields before conditions fields, not in sheet order | Low | → Studio polish |
| S11 | Likelihood oracle: levels cannot be reordered; a third level goes last | Low | → Studio polish |
| S14 | Flow steps: a condition step's band label is capped at 80 characters (checks allow 100); the error shows only on Save | Medium | → Studio polish |
| S15, S16 | Scene types: tall step cards; per-band effects of a check set in collapsed accordions | Low | → Studio polish |
| S17 | Scene types: no `sheet` effect on the Respite choice. By design ([ADR 0042](../../docs/adr/0042-flow-choices-check-sets-and-scene-tables.md)): a Scene Type's Setup runs before a Character is known, so its steps take none; the spec put the choice in Setup. The editor does not say why the kind is missing | Medium | Content: choice moved to Play (T3); the missing-kind hint → Studio polish |
| S20 | Flow hooks: `sheet` effect reported missing on the Unmasking XP choices and the closing prompt. Wrong: it is offered (S27) | — | — |
| S27 | Effects editor: the effect-kind select shows nine rows and `sheet` is the tenth, visible only by scrolling the open list; it misled the authoring twice (S17, S20) | High | Fixed in this feature |
| S21 | Flow hooks: a suggested choice must name its Skip option; the error shows only after adding the step | Low | → Studio polish |
| S22, S23 | Flows: a phase needs a Scene Type to save; the first phase key does not follow its name | Low | → Studio polish |
| S26 | Sheet: no bulk add or duplicate for fields (23 fields ≈ 450 actions, the costliest part) | Medium | → Studio polish |
| S3, S4, S12, S13, S18, S19 | Layout shifts, Radix option clicks by reference, slow screenshots | — | None (automation) |

## Playtest findings

Played 2026-10-26 by a Claude in Chrome worker as `mire-author@ogami.test`: campaign "The Mire Letter" on v2, Prologue, two Inquiry, one Struggle and one Respite scene, the Unmasking and its closing, to "Flow complete"; Push and Keep in a step, a push from the sheet, a marked Condition shrinking the next pool, Clues and Nightfall per band, both oracles, XP +3 and Conditions cleared at the close. No console errors; every `/api` call 200. Clues ≥ 4 was reached partly by setting the tracker by hand.

| ID | Finding | Kind | Severity | Plan |
|---|---|---|---|---|
| P1 | Credits notice under the GameSystem and in "About this game", with the license link | — | — | Works |
| P2 | Character step: the attribute budget counts 12; the 8 skill points have no counter or cap (one `pointBudget` per sheet, [ADR 0040](../../docs/adr/0040-structured-character-creation.md)) | Gap | Medium | Built in this feature (user, 2026-10-26): several point budgets per sheet |
| P3 | Check step: the pool size shows only after rolling; before, the formula with names | UX | Low | Follow-up |
| P4, P5, P8, P11 | Push and Keep in a step, push from the sheet (band effects rightly not applied), Respite clears, Stand Fast marks Dread, XP questions and the closing clear | — | — | Works |
| P6 | Oracle question field shows a placeholder that is not used when left blank | UX | Low | Follow-up |
| P7 | Table step: "then say how it fits" but no text field follows the roll | UX | Low | Follow-up |
| P9 | A `condition` step's outcome shows only as a journal "Flow outcome", not in the guided step area | UX | Medium | Follow-up |
| P10 | Nothing happens when Nightfall fills | Content | Medium | v3: Investigation scene closing `condition` on Nightfall with `endPhase` ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md)) |
| P12 | Six of eight skill rolls had no six; 30d6 summed 88 | — | — | Chance: the kernel uses `random_int` (`SecureRandomNumberGenerator.php:16`); 88 is −1.8σ |
| P13 | The letter writer NPC is not in the arrival scene's cast; added by hand | UX | Low | Follow-up |
| P14 | Push is offered on a success; the introduction said "a failed roll" | Content | Low | v3 wording: ADR 0041 pushes any roll with a missed die |
| P15 | "Flow complete. Play on freely."; End session keeps the journal | — | — | Works |
| P16 | "Upgrade to v3" restarts guidance at the first phase; trackers and the Character carry over, the character step offers the existing one | — | — | Works |
| P17 | v3: Nightfall 6 at a scene's end writes "Night has fallen… The phase ends after this scene" and goes straight to the Confrontation; the condition step form starts with only the Otherwise band and an empty tracker | — | Low | Works; form defaults → Studio polish |

## Progress

- 2026-10-26: explored ADR 0021, 0041, `preset-pbta`, `rules-dice-pools`; YZE Free Tabletop License checked (SRD only; no Vaesen material). User chose an original Vaesen-style game. Issue #294.
- 2026-10-26: T1 done (ed44865, docs only: structural readback, no runnable RED). License 1.1 (2026-03-31) read in Chrome: same SRD-only grant plus a generative-AI disclosure (section 3), so the notice states it. T2 started: content spec written, a Claude in Chrome worker authors it as `mire-author@ogami.test`.
- 2026-10-26: T2 done: v1 published from Studio (export 1,580 lines, above the ~1,300 forecast); 26 Studio findings. S17 traced to ADR 0042 (no sheet effects in a Scene Type's Setup); S20 not reproducible from the code (`BranchingFields.tsx:409`, `StepListEditor.tsx:351`). T3 started: a worker moves the Respite choice to Play, adds the XP and clear effects (v2), then plays a session.
- 2026-10-26: T3: v2 published (S27: `sheet` hidden as the tenth row of the kind list); session played to the end on v2; 15 Play findings, no bugs. User chose to build several point budgets per sheet here (P2). v3 (P10 Nightfall end, P14 wording) in progress.
- 2026-10-26: T3 done: v3 published (Investigation scene closing `condition` on Nightfall with `endPhase`; push wording); the v2 campaign upgraded to v3 and Nightfall 6 ended the phase into the Confrontation (P17). Point budget map recorded in the slice plan.
- 2026-10-26: T4 done: exported v3 unedited (1,653 lines); re-export to a temp path `cmp` byte-identical (temp deleted); `make presets` prints `Unchanged lanterns-of-the-mire (v3, managed in Studio)`; no "Vaesen" in the file. `PresetsTest::lanternsOfTheMire...` passed first against the existing file (77 assertions); proved live by flipping the point budget total 12 to 13 (failed), then restored (PresetsTest 9 tests green). The preset publishes as v1 on the test database.
- 2026-10-26: T6 done (writer, Sonnet): `sheet.pointBudgets` in the publisher (`PointBudget::validate`, one-entry canonicalisation to `pointBudget`), draft commands `SaveSheetPointBudgetEntry` / `RemoveSheetPointBudgetEntry`, routes `PUT`/`DELETE …/sheet/point-budgets/{budget}`, `several_point_budgets` 409 on the single-budget routes, view `pointBudgets`. RED: `ReleaseContentPointBudgetsTest` (22 of 28 failed, 4 errors) and `GameSystemSheetPointBudgetsTest` (12 errors, 1 failure) before the code, then green; handler and API tests were written after the application layer, so no separate RED. Size ~1,480 changed lines (domain and application ~430, Studio HTTP ~230, tests ~790, schema ~30); `make api` adds ~660 generated lines (openapi.json 376, schema.d.ts 281), not committed here.
- 2026-10-26: T5 done: [ADR 0043](../../docs/adr/0043-several-point-budgets.md) (docs only: structural readback, no runnable RED). Settled: a one-entry `pointBudgets` canonicalizes to the legacy `pointBudget` in the publisher, so every publish path hashes alike; a legacy budget reads as key `points`; a draft keeps a legacy budget as restored until a keyed write (a label-less one round-trips); keyed routes `…/sheet/point-budgets/{budget}`; the single-budget endpoint answers 409 `several_point_budgets` with two or more. Contract doc updated, its claim that Play refuses budgets fixed.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | — | ed44865, 43ab5a1 | Review on 43ab5a1 offered, skipped by the user; no findings | [#295](https://github.com/iribarren/ogami/pull/295) |

## Next step

Slice 2 (T5–T7): ADR 0043 several point budgets, then contract, Studio and Play backend.
