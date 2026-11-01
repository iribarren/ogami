# Feature: preset-west-marches

- **Locator:** `odd/tasks/preset-west-marches.md` · Engram topic `odd/preset-west-marches/tasks`
- **Issue:** [#337](https://github.com/iribarren/ogami/issues/337) · **Branch:** `feat/preset-west-marches-1-release` · **Status:** in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-places` (PRs #331–#336)

## Objective

Roadmap backlog B15. A West Marches preset compatible with fifth edition, on SRD 5.2.1 (CC BY 4.0), following [example 5](../../docs/domain/flow-examples.md#5-west-marches): a founding phase with a town and a roster of adventurers; an expeditions loop where each session picks a rumor and a party, travels, explores and returns to town before the session ends; encounter tables by distance. Uses places and a class `select` field ([ADR 0047](../../docs/adr/0047-places.md), [ADR 0048](../../docs/adr/0048-select-sheet-fields.md)), conditions ([ADR 0041](../../docs/adr/0041-dice-pools-pushes-and-conditions.md)), shops ([ADR 0045](../../docs/adr/0045-gear-inventories-currency-and-shops.md)), HP and spell slots as sheet effects ([ADR 0027](../../docs/adr/0027-sheet-effects.md)). Credits carry the attribution ([ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)).

Out of scope: contract, Play or Studio changes (exploration found every element of example 5 in schema version 2); the rules gaps below; levels above 10.

## Problem and why

- No shipped preset uses places, `select` fields or several Characters per Campaign. Only the contract fixture `backend/tests/Fixtures/Studio/releases/examples/west-marches.json` shows this flow, and it has no sheet, checks or gear.
- This is the first preset on the most played open rules set, and the first that exercises places, conditions, shops and sheet effects together.

## Decisions

| Question | Decision |
|---|---|
| SRD version and legal text (Claude, from the SRD's Legal Information page) | SRD 5.2.1, the current version (the roadmap says 5.2). `credits.notice` opens with the required statement, verbatim: "This work includes material from the System Reference Document 5.2.1 (“SRD 5.2.1”) by Wizards of the Coast LLC, available at https://www.dndbeyond.com/srd. The SRD 5.2.1 is licensed under the Creative Commons Attribution 4.0 International License, available at https://creativecommons.org/licenses/by/4.0/legalcode." It may be followed by "Rules summarized and adapted for guided solo play." (CC BY asks to indicate changes). `license`: "CC BY 4.0"; `url`: `https://creativecommons.org/licenses/by/4.0/legalcode` |
| Naming (Claude, same page) | The SRD forbids any other attribution to Wizards and allows "compatible with fifth edition" or "5E compatible". Key `west-marches`, name "West Marches", description says "5E compatible". No "Dungeons & Dragons", "D&D" or Wizards anywhere else in the release |
| Content rule (ADR 0018 decision 29) | Rules summaries in own words; SRD terms (class, species, condition, gear and monster names) as game terms. Every monster, class, species, background and gear name and price is checked against the SRD text |
| Sheet (Claude) | `class` select (the 12 SRD classes), `species` select (9), `background` select (4), `level` number 1–10, `xp`. `str`…`cha` number (min 3, max 20, `roll` `4d6kh3`) with a point budget total 72, min 8, max 15 (the standard array fits; the point-buy cost table cannot be expressed). Derived `str-mod`…`cha-mod` `({str}-10)/2` (floor division), `prof` `2+({level}-1)/4`. Number `ac`, `hit-die` (6–12, default 8), `casting-mod`, `exhaustion` 0–6, `death-saves` 0–3. Track `hp` (`startMax` `max(1,{hit-die}+{con-mod})`), track `hit-dice` (`startMax` `{level}`), tracks `slots-1`…`slots-5` with no `max` (the player sets them; 0 for non-casters). `conditions` field with the 14 SRD conditions other than Exhaustion, which is the number field because it scales. Lists `proficiencies`, `features`, `spells`. `gp` number min 0 default 50, `sheet.currency {field: gp, unit: "GP"}`, `pack` inventory capacity 30, categories Gear, Weapons, Armor. Layout groups: Adventurer, Abilities, Combat, Spellcasting, Kit |
| Levels (Claude) | Capped at 10, so slot levels 1–5 are enough and a long rest fits the 10-effect limit. Levelling up is by hand (raise `level`, then the track maxes): effects cannot change a track's max. Town has a prompt with the SRD XP thresholds |
| Checks (Claude) | d20 + modifier against a roll-time DC, bands `fail` and `success`. A roll-time mode does not exist, so advantage and disadvantage are separate checks (`2d20kh1`, `2d20kl1`): `str`, `str-adv`, `str-dis`, and so on for the six abilities (18), `melee` (`{str-mod}+{prof}`), `ranged` (`{dex-mod}+{prof}`), `spell` (`{casting-mod}+{prof}`) in three modes each (9), `defend`, `death-save` (29 of 50). Every d20 test modifier ends in `-2*{exhaustion}`. A saving throw or skill proficiency is added as the situational modifier (the hint says so) |
| Offering ability tests (Claude) | A check set holds at most 10, so 18 do not fit. A generic test is a `choice` "Roll with…" (normal, advantage, disadvantage) followed by one check step per mode, each with its six checks. A situational test (a trap, a poison) offers one ability in three modes |
| Combat (Claude) | Abstract, because a tracker effect takes no typed damage and foes have no sheets: `foe` counter 0–10 (hits to fell it), `threat` counter 0–3, levels None / Minor / Tough / Deadly. Encounter entries name SRD monsters. `combat` names the foe (`createNpc [monster]`) and bands on `away` set threat and foe (near 1/2, mid 2/3, deep 3/5), then `nextScene combat-round`. `combat-round`: attack (check set of `melee`, `ranged`, `spell` in three modes, DC "Foe's Armor Class"), `success` → `foe` −1; optional slot spend; optional potion; `foe` at 0 → `victory`; else `defend`, whose hit takes `hp` `{formula: "{tracker:threat}*4"}`; optional `death-save`; "Fight on or flee?". `victory`: "Who takes the spoils?" adds `gp` and `xp` by threat |
| `defend` (Claude) | A monster's attack against the Character's `ac`: 1d20, difficulty "Foe's attack bonus" (default 4, 0–15), band `miss` `upTo {ac}-1-{check:difficulty}`, last band `hit` |
| Death saves (Claude) | `death-save` is a plain 1d20 with no modifier, so its bands are exact: `fail` up to 9 (`death-saves` +1), `success` up to 19, `rise` on 20 (`hp` set 1, `death-saves` set 0) |
| World turn guard (Claude, from the `preset-cpr-campaign` lesson) | Only the last `nextScene` counts, so a world turn encounter would replace a pending `combat-round` chain. `worldTurn` starts with a condition on `foe` (upTo 0 continues, rest `next: end`). Encounter's "Fight" option and the table entries that force `combat` set `foe` 1 first, so the guard also protects the way in. A wandering monster may replace a pending Delve or Travel: accepted, that is what it does |
| Roster (Claude, amends example 5) | Founding has `character` steps: three mandatory, two suggested. A Character has no release Tags from a `character` step, so the party `pick` names none, as the fixture does (`of: character`, `mode: choose`, `count {1,5}`). Tag `adventurer` is dropped; `hireling`, `patron`, `rival`, `monster` stay |
| Places (Claude, amends example 5) | Fact `town` is a `place` slot. Place Tags `town`, `site`, `near`, `mid`, `deep`. `explore-site`: pick place [site], `found` continues; `none` rolls `site-features`, asks a name and `createPlace` with `site` and the band tag from `away`. Distance stays on tracker bands, as ADR 0047 decided |
| Expedition Thread (example 5) | `sessionOpening`: pick Thread (mode `choose`); `none` rolls `rumors` → `createThread` so a closed-out campaign never stalls; `fillFact expedition`; party pick; `away` set 0. `sessionClosing`: `closeThread {fact: expedition}` on "resolved" |
| Rests (Claude) | `choice` options with sheet effects apply to one Character per completion, so a forced Scene Type `rest` chains itself ("Another adventurer rests?" → `nextScene rest`; `next` is forward only). Short rest: `hit-dice` −1, `hp` + `{hit-die}/2+1+{con-mod}` (the SRD's fixed average). Long rest: `hp` to max, the five slots to max, `hit-dice` + half, `death-saves` 0, `exhaustion` −1. Town's inn costs 1 GP and adds no risk; `camp` costs 1 ration and draws the world turn, per adventurer: accepted as camp risk |
| Rations and torches (example 5) | `rations` counter 0–20, start 5; `torches` clock 6. Travel: `rations` −1, then at 0 "Who goes hungry?" (`exhaustion` +1). Delve: `torches` +1, then a full clock prompts "The last torch gutters". Town's "Stock up" option: `gp` −3, `rations` +5, `torches` set 0 (refused with `insufficient_funds` when broke) |
| Gear and shops (Claude) | SRD prices rounded up to whole GP (rations and torches are Town's "Stock up", not gear). Town offers two shops: outfitter (Gear) and smith (Weapons, Armor), `sellRate` 50, stock `{dice: "1d4+2"}`. A Potion of Healing is gear; drinking it is a choice option `hp` + `{dice: "2d4+2"}`, and the player removes the stack by hand (gear has no effects, ADR 0045) |
| Traps and conditions (Claude) | `rooms` entries with keys branch by entry: a trap → DEX test in three modes, `fail` `hp` −`{dice: "2d6"}`; a poison needle → CON test in three modes, `fail` `hp` −`{dice: "1d6"}` and `mark poisoned`; a lair → `foe` 1, `nextScene combat`. A long rest clears nothing else: conditions are cleared by hand or by a `Who recovers?` option |
| Rules gaps accepted (Claude) | Natural-20 criticals on a modified roll (hint: count a 20 on the die as a success and, on an attack, a double `foe` −1 by hand); a condition imposing advantage (the player picks the mode); item effects; proficiency or slots by class (`select` is not readable by formulas, ADR 0048). No code change: each stays a hint. They are listed in the `flow-examples` note, not as follow-ups, unless the play pass shows one blocks play |
| Combat and flow details (writer, slice 1) | Fact slot `foe` (npc) names the monster across the chain (`{fact:foe}`). The world turn guard checks `foe` and also `threat`: `threat` stays above 0 until Victory's closing (or a flee) resets it, so the `victory` chain is not replaced either. A Scene Type's `setup` takes no sheet effect, so the combat round's checks run in `setup`, the slot and potion options in `play`, and "Fight on or flee?" in `closing`. Delve's closing guard (`foe` above 0) keeps a lair's `nextScene combat` from being overwritten by "Next room, or leave?". `sessionOpening` also sets `foe` and `threat` to 0. Travel's "Who goes hungry?" follows the design literally (at 0 rations after the leg). A rest's "Who recovers?" clears every condition of the picked adventurer. Travel's `march` also sets `foe` to 0 (a harmless reset that keeps Delve's leave option from drawing the "does not lower tracker" authoring warning). Founding takes the first session: the expeditions' `sessionOpening` runs when the next session starts. The opening party pick adds to the Session party, which a new session inherits from the last |
| Authoring route (Claude) | The writer authors the JSON by hand in `night-job.json`'s compact style. A Chrome play pass (slice 2) replaces a separate playtest |

## Design

### Flow `west-marches`

`defaultView: focus`; an introduction in the style of the other presets (solo play in one paragraph, the roster, one expedition per session, end in town, rules come from the SRD). `flow.trackers`: `away`, `rations`, `torches`, `foe`, `threat`. `flow.oracles`: every table the flow rolls or shortcuts, plus `fate`.

| Phase | Mode | Selection | Scene Types |
|---|---|---|---|
| `founding` | once | sequence | `founding-town` → `roster` |
| `expeditions` | loop | player | `town`, `travel`, `explore-site`, `delve`, `camp`, `return` (`encounter`, `combat`, `combat-round`, `rest` through `nextScene`) |

- `founding-town`: prompt "What is the town called?" → `createPlace [town]`, `fillFact town`; prompt "What is it like?"; table `rumors` → `createThread`, twice.
- `roster`: `character` × 5 (see Decisions); prompt "Who else stands out?" → optional `createNpc [patron]`.
- `town`: prompts for news and hirelings (`createNpc [hireling]`, suggested); level-up prompt with the XP thresholds; "Stock up"; outfitter and smith shops; "Rest at the inn" (`gp` −1, `nextScene rest`).
- `travel`: `away` +1, `rations` −1, hungry band; prompt "Where to?".
- `explore-site`: Place pick, `site-features`, generic ability test.
- `delve`: `rooms` with branches, `torches` +1; closing choice "Next room, or leave?" (next → `nextScene delve`; leave → `nextScene travel`).
- `camp`: `rations` −1, `nextScene rest`.
- `return`: `away` set 0; prompt "What did you bring home?".
- `encounter`: prompt "What do they want?", generic social test, "Fight?" (yes → `foe` 1, `nextScene combat`).
- `worldTurn`: the `foe` guard; `away` upTo 0 → end; roll 1d6, upTo 1 → `away` bands → `encounters-near` / `-mid` / `-deep`; entries `nextScene encounter` or `foe` 1 + `nextScene combat`.
- `sessionClosing`: `away` upTo 0 → "Safe in town."; rest → `stranded`; "Is {fact:expedition} resolved?" → `closeThread`; table `rumors` → `createThread` (suggested).
- Tables: `rumors` 12, `encounters-near` / `-mid` / `-deep` 8 each (SRD monsters of rising challenge and peaceful meetings), `site-features` 8, `rooms` 10, `stranded` 6, `spoils` 6; likelihood `fate`.

## Constraints

- Schema version 2; no contract, Play, Studio or API change. If the writer finds the contract refuses part of this design, they stop and report instead of changing code.
- Publishing prints no authoring warning for this release.
- Every slice safe on `main` alone: slice 1 publishes a complete, tested release; slice 2 adds an e2e, play-pass fixes and closing docs.

## Slice plan

Forecasts include tests and docs (no generated files: no API change). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-west-marches-1-release` | `feat(presets): preset-west-marches 1/2 release` | T1, T2, T3 | ~1,600 (JSON ~900, tests ~450, docs ~250) |
| 2 | `feat/preset-west-marches-2-play-pass` | `feat(presets): preset-west-marches 2/2 play pass` | T4, T5, T6 | ~600 |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; example 5 in `docs/domain/flow-examples.md` amended (places, roster of Characters, `foe` guard, rests, stale "until `play-places`" note, rules gaps); README preset list if it has one | delegated writer (slice 1) | [x] | |
| T2 | 1 | `backend/presets/west-marches.json` base: credits, tags, facts, trackers, sheet, checks, gear; `PresetsTest` (publishes once, credits verbatim, select options, conditions, currency, shops' categories, checks) | delegated writer (slice 1) | [x] | |
| T3 | 1 | Flow, Scene Types and tables; `WestMarchesPresetFlowTest` playing founding, a session opening with no open Thread, travel with a world turn encounter by distance, a combat to victory, the `foe` guard, a trap with a condition, a rest chain, a shop purchase, a stranded closing and `closeThread` | delegated writer (slice 1) | [x] | |
| T4 | 2 | Slice 1 review fixes; e2e `frontend/e2e/play-west-marches.spec.ts`: founding, session opening, a town purchase | delegated writer (slice 2) | [ ] | |
| T5 | 2 | Chrome play pass: founding, one full expedition, a fight, a rest; fixes | parent Chrome pass + delegated writer (slice 2) | [ ] | |
| T6 | 2 | Closing: Reviews, roadmap `Done:` line under B15, follow-ups if any | parent (slice 2) | [ ] | |

## Progress

- 2026-11-01: Exploration: every element of example 5 exists in schema version 2. Gaps are rules, not flow: natural-20 criticals, conditions imposing advantage, item effects, values by class. Decided hints, no code change. SRD Legal Information page read: the 5.2.1 statement is verbatim and exclusive.
- 2026-11-01: T1 done: feature doc and flow example 5 amended (README lists two of seven presets, so it is left alone).
- 2026-11-01: T2 done: release base publishes with no flow until T3. RED: three `PresetsTest` cases failed on the missing file; GREEN with the JSON (112 assertions).
- 2026-11-01: T3 done: flow, 13 Scene Types and 8 tables; `PresetsTest` flow case and `WestMarchesPresetFlowTest` (22 tests). RED: the 22 flow tests errored on the release without a flow; removing the `threat` guard step made the victory test fail, so the guard is covered.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1 writer: T1, T2, T3 on `feat/preset-west-marches-1-release`.
