# Feature: play-sheet-display-polish

- **Locator:** `odd/tasks/play-sheet-display-polish.md` · Engram topic `odd/play-sheet-display-polish/tasks`
- **Issue:** [#340](https://github.com/iribarren/ogami/issues/340) · **Branch:** `feat/play-sheet-display-polish-1-display-and-plain-checks` · **Status:** done
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `preset-west-marches` (#337, PR #339), whose Chrome play pass found these

## Objective

Roadmap backlog B18. Fix five issues in Play's general UI: raw sheet field keys and no-op changes in the journal, raw formulas on the Character form, a situational modifier a check cannot turn off, and a wrong header between sessions.

## Problem and why

- West Marches defaults to the Focus view, and `FocusPanel` is the only `JournalEntryView` call site that omits `sheet`, so the player reads internal keys.
- Nothing filters a sheet Effect that changed nothing, so a long rest on a healthy adventurer buries what changed under lines such as `hp 7 → 7`.
- `creation.ts` interpolates a track's `startMax` formula into the field's hint, so the player reads formula syntax.
- ADR 0024 says the player "can always add" a situational modifier. A plain roll such as a death save has no way to refuse it, so West Marches' tip has to tell the player not to use the field.
- After a session ends `currentSessionNumber` is null, so the header says "No session yet" even when earlier sessions exist.

## Decisions

| Item | Decision | By |
|---|---|---|
| 4 | Checks can opt out of the situational modifier with an optional release property | User, 2026-11-03 |
| 4 name | `noSituationalModifier: true`; absent or false keeps today's behaviour, so existing releases are unchanged. A negative flag fits the sheet and check validators, which drop `false` values | Parent |
| 4 refusal | A non-zero value on an opted-out check is refused with a 422, as a difficulty on a check without one is. Zero stays allowed | Parent |
| 4 signal | No inference from the data: "no modifier and no difficulty" also matches all 23 `percentile-horror` checks, which rely on the situational modifier | Parent |
| 2 no-op | A change is a no-op when `before` equals `after` and it rolled no dice. `hp 7 → 7 (1d8: 4)` stays: it tells why nothing changed | Parent |
| 2 place | Filter in the two Flow handlers before their empty check, not in `SheetChangeContent::of` (stored entries load through it and it throws on an empty list) and not in `checkedChanges` (push and burn share it). The frontend also hides no-ops, because stored journals keep them | Parent |
| 3 wording | Computable: `Set at creation: 7`. Otherwise `Set at creation from Hit die and Con modifier`. Never formula syntax | Parent |
| 5 wording | `Session N ended`, naming the last session. `No session yet` only when there are no sessions | Parent |

## Constraints

- Campaigns stay pinned to their release, so West Marches campaigns on v2 see the modifier until they upgrade.
- No API change except the new check property.

## Slice plan

Forecasts include tests, docs and generated files. Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-sheet-display-polish-1-display-and-plain-checks` | `feat(play): play-sheet-display-polish 1/1 sheet display and plain checks` | T1–T4 | ~1,150: this doc ~80; T1 ~120; T2 ~100; T3 ~20; T4 ~400 plus docs ~30 and generated OpenAPI and TS ~400 |

## Tasks

| ID | Task | Route | Done | Commit |
|---|---|---|---|---|
| T1 | Journal: labels and currency unit in the Focus view; no-op sheet changes neither recorded nor shown | delegated: 2+ non-trivial files | [x] | `2eb15507` |
| T2 | Character form: a value or a plain explanation instead of a `startMax` formula | delegated: 2+ non-trivial files | [x] | `8c7a1ee6` |
| T3 | Header: `Session N ended` between sessions | delegated: slice runs under one writer | [x] | `d6334a5d` |
| T4 | `noSituationalModifier`: contract, Studio, Play, form, West Marches' death save | delegated: 2+ non-trivial files | [x] | `f15b42bb` |
| T5 | Review findings: pending-check no-ops, canonical flag, pool opt-out, Focus currency | delegated: follows the slice's writer | [x] | `d8b89d3c`, `5841a74d` |

## Applicable checks

- `make verify` once at the end; CI runs the full suites.
- Focused PHPUnit and Vitest while iterating, test first.

## Progress

- 2026-11-03: Mapped the five items and took the decision on item 4. One slice, delegated writer: two or more non-trivial files.
- 2026-11-03: T1 done. RED: 2 handler tests failed without the filter, then GREEN; frontend tests added with the fix (stored all-no-op entries hidden in Journal and Focus).
- 2026-11-03: T2 done. Code written with the tests, so no separate RED; the panel test's old literal-formula assertion is the changed behaviour. Derived modifiers on the form (`CharacterForm.tsx:200`) left alone.
- 2026-11-03: T3 done. RED: 2 CampaignPage tests failed without the change. The e2e checks (`campaigns.spec.ts:25`, `play-session.spec.ts:19`) use fresh campaigns: unchanged.
- 2026-11-03: T4 done. Studio draft stores the flag as sent; only the published release drops `false`. Play refuses with `SituationalModifierNotAllowed` (422 on journal and FlowRun routes); a push or burn cannot add a modifier (no `perform`). Tests written with the code, no separate RED.
- 2026-11-03: T5 done (tests only, one comment). Pending-check no-op filter: 2 API tests fail without it, pass with it. Canonical flag: an explicit null never reached the `isset`, because `ReleaseFields::object()` drops nulls, so the new true/false/null test passed at once (no RED); comment corrected. Pool opt-out: unit test fails with the refusal removed; `CheckForm.test.tsx` added, the "before the extra dice" suffix was already gated by the flag; the vacuous dialog assertion removed. Focus: the shop entry test fails without `currencyUnit`.
- 2026-11-03: CI found `studio-check-editor.spec.ts` matching two `Modifier` labels once the new checkbox existed; fixed with an exact match, spec passes locally. Not exercised by hand in a browser.

## Reviews

| Slice | Lineage | Outcome | Findings | PR |
|---|---|---|---|---|
| 1, T1–T4 | `review-dd60e7f45dc39ce2` | Approved and acknowledged | 1 warning, 3 suggestions, all on missing test proof: fixed in T5. Its claims of a missing import and of a null kept in the canonical check were wrong: the import exists, and `ReleaseFields::object()` drops nulls | [#371](https://github.com/iribarren/ogami/pull/371) |
| 1, T5 and e2e fix | None | Skipped by the user: tests and one comment, 148 lines | None | [#371](https://github.com/iribarren/ogami/pull/371) |

## Next step

None. Follow-up [#372](https://github.com/iribarren/ogami/issues/372) tracks derived values on the Character form under Roll, which predate this feature.
