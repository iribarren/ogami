# Feature: preset-pbta

- **Locator:** `odd/tasks/preset-pbta.md` · Engram topic `odd/preset-pbta/tasks`
- **Issue:** #234 · **Branch:** `feat/preset-pbta-2-preset` · **Status:** done (slice 2 in PR #236)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-journal-attachments` (PRs #232–#233)

## Objective

Roadmap backlog B7, the first end-to-end proof of M4 on a new system: author a Powered by the Apocalypse preset **in Studio**, publish it, play a session, and keep the published release as a file in `backend/presets/` so `make presets` keeps it. Every Studio friction becomes a finding for `studio-ui-polish` (B7b).

Out of scope: contract changes (2d6 + stat with three bands fits today, [ADR 0024](../../docs/adr/0024-structured-checks-without-scripting.md):42); fixing Studio findings (B7b); per-NPC values such as Strings counts; JSON import into Studio.

## Problem and why

- Every preset so far is hand-written JSON; nothing proves a manager can build a playable system in Studio alone.
- Nothing exports a release: the release endpoints return summaries only, and [ADR 0028](../../docs/adr/0028-studio-gamesystem-drafts.md):14 says "No JSON import or export".
- `make presets` fails on a key a Studio draft owns (`GameSystemManagedInStudio`, `PublishGameSystemReleaseHandler.php`), so an exported Studio release would break it on the dev database.

## Decisions

| Question | Decision |
|---|---|
| Which game | Thirsty Sword Lesbians (Claude, 2026-10-20): its text is open under CC BY-SA 4.0 ("Powered by Lesbians" license). Bluebeard's Bride (Magpie Games) has no published fan-content or community license |
| Export path | `app:gamesystem:export <key> [--release N]` writes the release's canonical JSON. `make presets` reports "Unchanged (managed in Studio)" when a Studio draft owns the key and its latest release has the file's hash; a different hash still fails. On a fresh database the file publishes as a normal preset. New ADR 0036 amends ADR 0028 (user, 2026-10-20) |
| Authoring route | Studio UI through Claude in Chrome, as a manager would; no API scripts, no JSON edits. The exported file is committed unedited |
| Conditions | One `number` field per Condition (0..1), read by its move's modifier as `- 2 * {condition}`; formulas cannot read tags |
| Choose-from-list outcomes | In the band labels and the move hint; there is no multi-select step |

## Publisher policy

- License: https://poweredbylesbians.com/license/ — text (not art) under CC BY-SA 4.0; derivatives and sales allowed; no apps exclusion.
- Asks: no "Thirsty Sword Lesbians" in our title ("for use with" is fine); no implied endorsement; no Evil Hat or Gay Spaceship Games logos; attribution where our credits go.
- Attribution (check against the live page when authoring): "This work uses material from the Thirsty Sword Lesbians roleplaying game (found at https://swordlesbians.com), designed by April Kit Walsh and published by Evil Hat Productions, LLC, pursuant to the open license available at poweredbylesbians.com. The CC BY SA 4.0 license is available at creativecommons.org/licenses/by-sa/4.0/."
- Share-alike: the preset file adapts the text, so it is CC BY-SA 4.0 (`credits.license`).
- PbtA framing: original mechanics need no permission from the Bakers; no PbtA logo.

## Preset outline

GameSystem `hearts-and-rapiers`, "Hearts & Rapiers", description "An unofficial one-shot of swashbuckling romance, for use with Thirsty Sword Lesbians." Original wording throughout; chapter references to the SRD (Basic Moves, Core Rules) instead of quoted text.

- **Sheet:** name, playbook (text); Daring, Grace, Heart, Wit, Spirit (`number`, −1..3, default 0); Conditions Angry, Frightened, Guilty, Hopeless, Insecure (`number` 0..1); a derived "Conditions marked" (sum); XP (`track` 0..5; 3 advances a one-shot); Strings (`list`).
- **Moves (checks), 2d6 + stat − 2 × its Condition, bands miss ≤ 6 / partial ≤ 9 / full:** Fight (Daring), Fight (Grace) − Frightened; Defy Disaster with each of the five stats − Hopeless; Entice (Heart) − Insecure; Figure Out a Person (Wit) − Angry; Emotional Support (Heart), (Spirit) − Guilty; Call on a Toxic Power (Spirit). Strings' +1 / +3 and forward / ongoing go in the roll-time modifier.
- **Oracles (original tables):** a yes/no likelihood oracle; Toxic power (Conqueror, Corrupter, Affliction, Tyrannical authority, Complacent privilege, Well-meaning liabilities); Story element (Mystery, Intrigue, Celebration, Utopia, Invasion, Revolution, Relationship triangle, Couple with an obstacle); Romantic complication; Location; Villain's next move.
- **Narrative:** tracker "Countdown" (counter 0..5); NPC tags Villain, Ally, Crush; scene types Swashbuckle (action), Heart-to-heart (feelings), Intrigue (plot).
- **Flow "One-shot":** Setup (once: character, toxic power and story element tables, villain → NPC, goal → Thread); Scenes (loop, player picks the scene type: intent → scene title, complication table, a move check with "mark XP" on a miss, countdown); Climax (once: confront the villain, end-of-session XP questions with sheet effects).

## Constraints

- Contract unchanged: schema version 2; the exported file is exactly what Studio published.
- Content rule ([ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) decision 29, [ADR 0021](../../docs/adr/0021-release-credits-for-fan-presets.md)): original wording, credits carry the attribution.
- Every slice safe on `main` alone: slice 1 adds a console command and a publisher skip; slice 2 adds a preset file.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice. Each slice's first commit carries the previous slice's review fixes.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/preset-pbta-1-export` | `feat(studio): preset-pbta 1/2 release export` | T1, T2, T3 | ~800 |
| 2 | `feat/preset-pbta-2-preset` | `feat(presets): preset-pbta 2/2 hearts and rapiers` | T4, T5, T6 | ~1,400 (the exported JSON ~900) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Docs: this feature doc; ADR 0036 (export a release as a preset file; Studio-owned key with the same hash is skipped) and ADR index row; ADR 0028 status note; roadmap B7 in progress | delegated writer (slice 1) | [x] | 84209a6 |
| T2 | 1 | Author `hearts-and-rapiers` in Studio from the outline, publish v1, log every Studio friction under Studio findings | parent (browser, worker) | [x] | — |
| T3 | 1 | `app:gamesystem:export <key> [--release N] [--output path]` (canonical pretty JSON, fails closed on an unknown key or version); console publisher reports "Unchanged (managed in Studio)" for a Studio-owned key whose latest release has the file's hash; unit and integration tests | delegated writer (slice 1) | [x] | 58d804d |
| T4 | 2 | Playtest a session in Play (browser); fix content gaps in Studio and republish; log Play findings | parent (browser, worker) | [x] | — |
| T5 | 2 | Export the latest release to `backend/presets/hearts-and-rapiers.json` unedited; `PresetsTest`; flow unit test; move band edge tests; e2e spec | delegated writer (slice 2) | [x] | d43bf84 |
| T6 | 2 | Wrap-up: roadmap B7 done; this doc closed | delegated writer (slice 2) | [x] | d4cc9dc |

## Studio findings

Authored 2026-10-20 by a Claude in Chrome worker as `pbta-author@ogami.test` (~3 h, ~340 browser actions). Release v1 published (hash `f48beaca`). Simplified: one end-of-session XP question without XP (S11); Scenes check fixed to Defy Disaster (Daring) (S12); static NPC and Thread names; likelihood oracle keeps two default levels.

| ID | Finding | Severity | Plan |
|---|---|---|---|
| S1 | Whole app: signed out mid-session, keystrokes landed on /login. Not a bug: the php log shows `POST /api/auth/logout` at 21:29:44 UTC followed by six more within a second (the automation activated the focused Sign out, key repeat), no expiry or deauthentication | — | None (automation) |
| S2 | Checks: no duplicate; 12 moves ≈ 30–40 actions each; no miss/partial/full band preset | High | → `studio-ui-polish` |
| S3 | Checks: new band defaults to key `outcome`; bound select must switch to "A number" first | Medium | → `studio-ui-polish` |
| S4 | Checks: form stays open as "Edit …" after create; new-check form moves | Low | → `studio-ui-polish` |
| S5 | Oracle tables: Add entry leaves Min/Max blank (no next range); rows ~200px with empty effects block; no bulk paste | High | → `studio-ui-polish` |
| S6 | Oracle tables: only the first coverage error shows (clear message) | Low | — |
| S7 | Tables, flows, checks: native selects not keyboard-friendly (typing doesn't select) | Medium | → `studio-ui-polish` |
| S8 | Scene Types: create navigates to the editor, unlike other creates | Medium | → `studio-ui-polish` |
| S9 | Flow editor: flow-level checkboxes set by synthetic input didn't persist; real clicks did. Automation artifact (synthetic events skip React's change handler) | — | None (automation) |
| S10 | Flow editor: new phase auto-adds a scene type; hooks are deep collapsed accordions; no step outline | High | → `studio-ui-polish` |
| S11 | Flow editor: no `sheet` effect on choice/prompt steps (XP questions can't award XP); `{answer}` placeholders undocumented in UI | Medium | Contract gap → `rules-flow-choices` (B8b); `{answer}` hint → `studio-ui-polish` |
| S12 | Flow editor: a check step binds one check, so "Defy Disaster with any stat" needs five steps | Medium | Contract gap → `rules-flow-choices` (B8b) |
| S13 | Flow editor: choice Skip error has no field link | Low | → `studio-ui-polish` |
| S14 | Flow editor: Save gives no visible feedback near the button; a failed save only showed in Validation. Bug candidate | High | → `studio-ui-polish` |
| S15 | Validation: only the first error, as a JSON path, no link to the field | High | → `studio-ui-polish` |
| S16 | Validation: scene-type oracles and step trackers must be enabled on the flow; caught only at validation | Medium | → `studio-ui-polish` |
| S17 | GameSystem page: tab not in the URL; publish banner shifts tabs ~36px | Medium | → `studio-ui-polish` |
| S18 | Sheet: key rule (a-z, 0-9, "-") only in the error | Low | → `studio-ui-polish` |
| S19 | Overview: notice counter not shown; formula field preview shows raw text | Low | → `studio-ui-polish` (notice counter done in slice 1; formula raw text not reproducible, 2026-10-22) |
| S20 | Dev build: TanStack devtools buttons steal clicks | Low | — |

## Playtest findings

Played 2026-10-21 by a Claude in Chrome worker as `pbta-author@ogami.test`: campaign "The Gilded Mask Affair" on v1, Setup, three scenes (Swashbuckle, Heart-to-heart, Intrigue) and the Climax, 12 move rolls (band edges 6 / 9 / 10 checked, Condition −2 and roll-time +1 / +3 applied), every oracle, the Countdown, three NPCs, two Threads, journal notes with attachments; no console errors, no failed requests. v2 and v3 published from Studio with content fixes, checked in a second campaign ("The Silver Gambit") on v3. Verdict: plays end to end as a PbtA one-shot; remaining gaps are contract (B8b) and polish.

| ID | Finding | Severity | Plan |
|---|---|---|---|
| P1 | Play, character step: two Name fields (built-in and a sheet `name` field) | Low | Fixed in v2 (field removed) |
| P2 | Play, sheet: XP as a `track` starts full (5/5); a track starts full by contract (HP-style) | Medium | Fixed in v3: XP is a `number` 0..5, default 0 |
| P3 | Flow, Climax: the Confront check gave no XP on a miss | Medium | Fixed in v2 |
| P4 | Flow, closing question: "Yes" says "mark 1 XP" but a choice cannot award it | Medium | → `rules-flow-choices` (B8b, S11); XP edited by hand |
| P5 | Flow, Scenes check: prompt said "the stat that fits" but the step is bound to Daring | Medium | Prompt reworded in v2; stat choice → B8b (S12) |
| P6 | Flow, scenes: the complication table is always Romantic complication, whatever the scene type | Low | Backlog: per-scene-type table in a flow step |
| P7 | Flow, Setup: villain and goal answers did not name the NPC and Thread | Medium | Fixed in v3 (`{answer}` names); Studio does not hint the placeholder (S11) |
| P8 | Play, sheet: the Strings list shows only a count in the summary | Low | → `studio-ui-polish` / Play polish |
| P9 | Play, roll card: a Condition's −2 is folded into the modifier with no breakdown | Low | Play polish backlog |
| P10 | Play: NPC tags (Villain, Ally, Crush) are offered on the PC sheet and the Thread form | Low | Backlog: tags scoped per entity |
| P11 | Play, checks: the move hint (the choose-from list) disappears from the result card | Medium | Play polish backlog |
| P12 | Play, journal: the Climax roll sits under "Flow step" above Scene 5, which reads "Nothing recorded" | Low | Play polish backlog |
| P13 | Play, Setup: ends with an empty auto-added Heart-to-heart scene (S10) | Low | → `studio-ui-polish` (S10) |
| P14 | Play, Setup: long character form; Conditions as 0..1 number inputs | Low | Play polish backlog (toggles for 0..1 fields) |
| P15 | Oracle: yes/no has only the two default levels, no odds explanation | Low | Content, kept |
| P16 | Automation: a stale click target once opened the wrong control | — | None (automation) |
| P17 | Flow, Setup: prompts asked for a name and a trait, so the NPC name became a sentence | Medium | Fixed in v4 (both Setup prompts ask for a short name / goal) |
| P18 | Flow, Setup: an NPC agenda cannot come from a later answer (one answer per prompt) | Low | Contract note, backlog |
| P19 | Play, Setup: the Create your hero form is unchanged (P14) | Low | Same as P14 |

## Progress

- 2026-10-20: explored ADR 0018/0021/0024/0028, prior preset features, `make presets`; licenses checked (TSL open CC BY-SA 4.0; Bluebeard's Bride none). Export decision taken with the user. Issue #234.
- 2026-10-20: T2 done: `hearts-and-rapiers` v1 published from Studio; modifier `{grace} - 2 * {frightened}` accepted; 20 Studio findings logged (S1–S20).
- 2026-10-21: slice 1 merged (#235). S1 traced to the automation (seven `POST /api/auth/logout` in one second in the php log), not a session expiry; S9 an automation artifact. S11 and S12 moved to new backlog row B8b `rules-flow-choices`.
- 2026-10-21: T4 done: one-shot played end to end on v1; content fixes published as v2 (duplicate Name, Climax XP, Scenes prompt), v3 (XP a `number` 0..5, `{answer}` NPC and Thread names) ; 19 Play findings (P1–P19).
- 2026-10-20: T1 done: ADR 0036 (Accepted), index row, ADR 0028 amendment note, roadmap B7 marked in progress. Docs only, so no runnable RED; structural readback of the links and the index.
- 2026-10-21: slice 1 review fixes (stdout OUTPUT_RAW, --release test with distinct content, export file mode follows umask); RED/GREEN: the style-tag/backslash stdout test failed with a JSON syntax error and the umask test saw 0600 instead of 0644, both pass after the fix; the --release test change is test-only (no RED).
- 2026-10-20: T3 done. RED: `PublishGameSystemReleaseHandlerTest::aStudioKeyWhoseLatestReleaseHasTheSameContentIsSkippedWhenOnlyIfChanged` threw `GameSystemManagedInStudio`; `ExportGameSystemReleaseConsoleCommandTest` failed on the missing `app:gamesystem:export`. GREEN: the skip lives in `PublishGameSystemReleaseHandler` (via `ReleasePublisher::latestHas`), the console says "managed in Studio" through the `IsGameSystemManagedInStudio` query; export reads `GetPublishedRelease`; round trip export, publish `--if-changed`, Unchanged passes for schema versions 1 and 2.
- 2026-10-21: T5 done: latest release is v4 (P17 prompt rewording published); `app:gamesystem:export hearts-and-rapiers --output presets/hearts-and-rapiers.json` committed unedited, re-export is byte-identical (`cmp`), `make presets` prints `Unchanged hearts-and-rapiers (v4, managed in Studio)` on the dev database. Tests: `PresetsTest` (publishes once as v1, credits with the CC BY SA 4.0 notice, flow, tables, sheet, 12 checks), `HeartsAndRapiersPresetFlowTest` (Setup names the villain NPC and goal Thread from `{answer}`, a missed move marks XP and advances the Countdown, a hit does not mark XP, a missed Confrontation marks XP; every move at totals 6 / 7 / 9 / 10 gives miss / partial / partial / full; every Condition costs 2, e.g. grace 1 and frightened 1 is a modifier of -1), `frontend/e2e/play-hearts-and-rapiers.spec.ts` (credits, hero, one move in the journal). RED: no RED for the preset file itself; tests written against it passed on the first run, so the band test was shown to be live by flipping one expected band (9 => full), which failed 12 cases, then restored. The e2e spec passed on the first run (no RED).
- 2026-10-21: T6 done: roadmap B7 marked done, this doc closed pending review and PR.
- 2026-10-21: slice 2 in PR #236 (review skipped by the user); credits checked word for word against poweredbylesbians.com/license; feature closed.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-1bb45d588bb974f8` | 84209a6, 58d804d | approved; 1 warning (stdout export goes through the console formatter: use `OUTPUT_RAW`), 2 suggestions (`--release` test publishes identical content; temp file mode 0600 survives the rename), fixed in slice 2 | [#235](https://github.com/iribarren/ogami/pull/235) |
| 2 | — | f78348f, d43bf84, d4cc9dc | review offered, skipped by the user (Studio-exported data already playtested, its tests, a 70-line fix of slice 1's findings) | [#236](https://github.com/iribarren/ogami/pull/236) |

## Next step

None. Follow-ups: B7b `studio-ui-polish` (Studio findings), B8b `rules-flow-choices` (S11, S12, P4, P5), Play polish findings P8–P14.
