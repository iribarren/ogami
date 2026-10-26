# Feature: preset-vaesen

- **Locator:** `odd/tasks/preset-vaesen.md` · Engram topic `odd/preset-vaesen/tasks`
- **Issue:** #294 · **Branch:** `feat/preset-vaesen-1-preset` · **Status:** in progress (slice 1)
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
| License | Free League's Year Zero Engine Free Tabletop License covers the **YZE SRD only**, in print, PDF or a virtual tabletop module, not video games; it excludes Free League's other material and brands and forbids implying affiliation. A preset is a tabletop module in a play aid, so the SRD fits. Vaesen's name, setting and text are not covered (user, 2026-10-26) |
| What we build | An original game, **Lanterns of the Mire** (key `lanterns-of-the-mire`), as Percentile Horror did for CoC: our own attribute, skill, Condition and table names and wording; public-domain Nordic folklore only; no "Vaesen" anywhere in the release |
| Authoring route | Studio UI through Claude in Chrome, as a manager would; no API scripts, no JSON edits. The exported file is committed unedited ([ADR 0036](../../docs/adr/0036-export-releases-as-preset-files.md)) |
| Pools | Pool `{attr} + {skill} - {conditions}`, d6, success at 6; a pool never drops below one die (ADR 0041) |
| Push cost | Mark one Condition of the matching kind, the player picks it (`mark` without a value) |
| Fear | Stand Fast (Reason + Heart) is a pool check without a push |

## Publisher policy

- License: Year Zero Engine Free Tabletop License (Fria Ligan AB), on [Free League's Free Tabletop Licenses page](https://freeleaguepublishing.com/free-tabletop-licenses/) (check the live URL when authoring; the site refuses scripted fetches). Text copy checked: [falchionttrpg.blogspot.com](https://falchionttrpg.blogspot.com/p/year-zero-engine-free-tabletop-license.html).
- Notice (section 6, recommended wording) in `credits.notice`: "Lanterns of the Mire is an original game. This game is not affiliated with, sponsored, or endorsed by Fria Ligan AB. The Year Zero Engine System Reference Document is used under Fria Ligan AB's Free Tabletop License." `credits.license` "Year Zero Engine Free Tabletop License"; `credits.url` the license page.
- No Free League logos or game names; the optional Year Zero Engine logo is not used.

## Preset outline

GameSystem `lanterns-of-the-mire`, "Lanterns of the Mire", description "An original mystery game of folk-beings and lantern light in the 1870s North, built on the Year Zero Engine SRD." Original wording throughout.

- **Sheet:** archetype, drive, dark secret (text); attributes Vigour, Finesse, Reason, Heart (`number` 2..5, default 2); twelve skills (`number` 0..5, default 0): Vigour: Brawl, Endure, Haul; Finesse: Shoot, Sneak, Nimble; Reason: Lore, Inquiry, Alertness; Heart: Persuade, Mend, Insight. Conditions `body` "Bodily harm" (Winded, Bruised, Bleeding) and `mind` "Strain on the mind" (Rattled, Dread, Despair); XP (`number` 0..20); Keepsakes and gear (`list`).
- **Checks (13 pool checks, d6, success at 6):** one per skill, pool `{attribute} + {skill} - {body}` for Vigour and Finesse skills, `- {mind}` for Reason and Heart skills; bands Failure (0), Success (1), Strong success (2+); push "Mark bodily harm" / "Mark strain on the mind" marks the matching Condition, player's pick. Stand Fast: `{reason} + {heart} - {mind}`, no push.
- **Oracles (original tables):** yes/no likelihood; The folk-being (d12: water-horse, forest-wife, drowned one, night-rider, marsh-light, changeling, barrow-dweller, church-grim, hill-folk, mine-keeper, hearth-gnome, grave-hound); What it wants (d8); Where the trouble lies (d8); A clue (d10); Complication (d8).
- **Narrative:** trackers Clues (counter 0..6) and Nightfall (clock 0..6, full: the folk-being strikes); NPC tags Witness, Suspect, Folk-being, Ally; scene types Inquiry, Struggle, Respite.
- **Flow "A Mystery":** Prologue (once: character; place table; who wrote the letter → NPC; the mystery → Thread); Investigation (loop: Inquiry: intent, clue table, a check set of Reason and Heart skills, Clues +1 on success, Nightfall +1 on failure; Struggle: complication table, a check set of Vigour and Finesse skills, Nightfall +1 on failure; Respite: clear bodily harm or strain on the mind); Unmasking (once: folk-being and its want tables, Stand Fast with a Condition on failure, a confrontation check set Lore / Persuade / Brawl); Aftermath (once: XP questions with sheet effects, conditions cleared).

## Constraints

- Contract unchanged: schema version 2; the exported file is exactly what Studio published.
- Content rule ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decision 29, [ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)): original wording, credits carry the notice.
- Every slice safe on `main` alone.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-vaesen-1-preset` | `feat(presets): preset-vaesen 1/2 lanterns of the mire` | T1–T4 | ~1,700: this doc and roadmap ~150; exported JSON ~1,300; `PresetsTest` ~100; flow tests and e2e move to slice 2 if the file is bigger |
| 2 | `fix/preset-vaesen-2-gaps` | `fix(play): preset-vaesen 2/2 session gaps` | T5–T7 | Decided after T3: the gaps the session reveals, flow tests, e2e spec, closing |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; roadmap B9 in progress (Vaesen-style original on the YZE SRD) | parent (inline, docs) | [x] | |
| T2 | 1 | Author `lanterns-of-the-mire` in Studio from the outline, sandbox-roll a pool and a push, publish v1; log every Studio friction under Studio findings | parent (browser, worker) | [ ] | — |
| T3 | 1 | Play a session in Play (browser): Prologue, Inquiry, Struggle, Respite, Unmasking, Aftermath; pushes from the sheet and from a step; fix content in Studio and republish; log Play findings and gaps | parent (browser, worker) | [ ] | — |
| T4 | 1 | Export the latest release to `backend/presets/lanterns-of-the-mire.json` unedited; `PresetsTest` (publishes, credits notice, 13 pool checks, push costs, conditions) | delegated writer | [ ] | |
| T5 | 2 | Fix the gaps T3 found (scope decided after T3) | delegated writer | [ ] | |
| T6 | 2 | Flow unit test (Clues and Nightfall, a pushed step marks a Condition, Respite clears) and e2e spec (credits, a pool roll in the journal) | delegated writer | [ ] | |
| T7 | 2 | Wrap-up: roadmap B9 done; this doc closed; late findings fixed or filed | delegated writer | [ ] | |

## Studio findings

None yet.

## Playtest findings

None yet.

## Progress

- 2026-10-26: explored ADR 0021, 0041, `preset-pbta`, `rules-dice-pools`; YZE Free Tabletop License checked (SRD only; no Vaesen material). User chose an original Vaesen-style game. Issue #294.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

T2: author `lanterns-of-the-mire` in Studio through Claude in Chrome.
