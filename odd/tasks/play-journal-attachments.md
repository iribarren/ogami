# Feature: play-journal-attachments

- **Locator:** `odd/tasks/play-journal-attachments.md` · Engram topic `odd/play-journal-attachments/tasks`
- **Issue:** #231 · **Branch:** `feat/play-journal-attachments-2-ui` · **Status:** in progress (slice 2)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `ui-theme-polish` (PR #229)

## Objective

In Play, a solo player writes a journal note and attaches hand-picked roll and oracle results from the current scene; the journal shows the note with its results together (roadmap backlog B6). Builds on `play-campaign-journal`, where every result is recorded automatically as its own entry.

Out of scope: attaching results from other scenes; editing entries (a note's attachments are fixed when it is recorded); pagination.

## Problem and why

A scene's journal reads as a list of loose results with notes between them. The player wants to say "this roll and this oracle answer are what this paragraph is about", so the narrative and its dice read as one.

## Current code (explorer map, 2026-10-20)

- Entry: `backend/src/Play/Domain/Journal/JournalEntry.php:18-110`, immutable, append-only (`JournalEntryRepository.php:10-27`); fields `id`, `campaignId`, `sessionNumber`, `sceneNumber`, `recordedAt`, `content`, optional `flowStep`. `record()` places it in the current scene or throws `NoCurrentScene`. Ordered by `recordedAt`, then `id`.
- Content kinds: one `JournalEntryContent` class per `KIND` (`note`, `roll`, `oracle-table`, `likelihood`, `check`, `choice`, `pick`, `sheet-change`, `flowOutcome`), dispatched by `JournalEntryContents.php:25-36`. `NoteContent.php:10-66` trims, rejects blank or over 10,000 characters, stores `{kind, text}`.
- Note write: `POST /api/campaigns/{campaignId}/journal/notes` (`Infrastructure/Http/JournalController.php:96-122`, `RecordNoteRequest`) → `RecordNote` → `RecordNoteHandler.php:28-33` → `CampaignJournal::record()`; read back with `GetJournalEntry`, 201. Errors 400, 404, 409, 415, 422.
- All writers go through `Application/CampaignJournal.php`. The only cross-entry link today: `OracleTableContent.forEntry` (random event → its likelihood question), checked by `CampaignJournal::assertRandomEventQuestion` (~`:144-161`, loads all campaign entries) and a partial unique index (`migrations/Version20261019110000.php`).
- Persistence: `play_journal_entry`, `content` JSONB (`JournalEntryContentType.php`); legacy rows are read by `fromArray` treating missing keys as empty (`OracleTableContent.php:61-62, 90`).
- Read: `GET …/journal` → `GetJournalHandler.php:23-28` → `JournalEntryResponse.php:28-62` (`content` is a union of nine `*ContentResponse` by `kind`); OpenAPI from Nelmio attributes, `make api`.
- Frontend: `play/journal/useJournal.ts` (`useRecordEntry` appends the returned entry to the cache, `useRecordNote` `:82-90`), `NoteComposer.tsx:13-55` (below the journal, `campaigns/CampaignPage.tsx:149-152`, disabled without a scene), `Journal.tsx:23-148` + `groupJournal.ts:20-56` (session → scene), `JournalEntryView.tsx` (renderer per kind, note at `:262`), `randomEvents.ts:15-22` (`isListedRandomEvent` folds a random event under its question: the precedent). Current scene from `campaign.currentSessionNumber/currentSceneNumber` (`CampaignPage.tsx:98-101`, passed as `current`). `FocusPanel` shows scene content in Focus mode. `journal/fixtures.ts` types entries from `schema.d.ts`.
- Tests: `tests/Unit/Play/Application/RecordNoteHandlerTest.php`, `GetJournalHandlerTest.php`; `tests/Integration/Play/Http/JournalApiTest.php`, `PlayCampaignJournalThroughTheBusesTest.php`, `Http/PlayRequestSchemasTest.php`; Behat `features/play/journal.feature` (`PlayContext.php:690-700`); Vitest `journal/*.test.tsx`, `groupJournal.test.ts`, `CampaignPage.test.tsx`; e2e `frontend/e2e/play-session.spec.ts:20, 36-37`.
- Docs: `docs/domain/glossary.md:86` lists only four JournalEntry kinds (code has nine); ADR 0016 says every result is a real `JournalEntry`.

## Decisions (2026-10-20)

| Question | Decision |
|---|---|
| Display (user) | An attached result is **folded under its note**: hidden from its own place in the scene and shown inside the note, like a random event under its question. Entries stay immutable; only the display changes |
| Attachable kinds (user) | `roll`, `oracle-table`, `likelihood`, `check`. Not `note`, `choice`, `pick`, `sheet-change`, `flowOutcome`. A random-event oracle result (`oracle-table` with `forEntry`) is not attachable: it already lives under its question. Results recorded by a Flow step are attachable |
| Model | `NoteContent` gains `attachments`: an ordered list of entry ids (as picked), stored in the note's JSONB `{kind, text, attachments}`. The note references the results, never the other way round. Legacy notes without the key read as `[]` |
| Same scene | Every attached entry belongs to the same campaign and to the note's session and scene (the current scene when the note is recorded). A scene change between picking and posting makes the request fail, nothing is recorded |
| Validation errors | Unknown id, another campaign's entry, another scene's entry, a kind that is not attachable, a random-event result, a duplicate id, more than 20 attachments, a malformed id: **422** with a clear message, nothing recorded. Missing or `null` `attachments` = none |
| Several notes | A result may be attached to more than one note (no unique index); it shows under each |
| Text | Note text stays required (blank still rejected), with or without attachments |
| API | `POST …/journal/notes` gains an optional nullable `attachments: string[]`. `NoteContentResponse` gains a required `attachments: string[]` (ids, `[]` when none); the client resolves them from the journal it already holds, as `randomEvents.ts` does. No new endpoint |
| Lookup | One `ofId` per attached id, at most 20 primary-key finds; never the whole campaign (no new repository method) |
| Picker (slice 2) | In `NoteComposer`: a toggle list of the current scene's attachable results (newest first, each with a short label from the existing entry renderers), checkbox per result, order = journal order. Cleared after a successful post |
| Folding (slice 2) | `Journal` hides every entry attached to any note in the scene and renders the attached entries inside the note card, in the note's order; `FocusPanel` lists the scene's entries (`FocusPanel.tsx:96-100`), so it follows the same rule |

## Constraints

- Context boundaries and framework-free Domain (PHPat). Entries stay append-only and immutable.
- Each slice is safe on `main` alone: slice 1 adds the optional field and the response field; the UI ignores attachments until slice 2.
- Planning heuristic only: forecasts are not caps; the slice ceiling is ~1,700 changed lines including generated files.

## Slice plan

| Slice | Branch | Tasks | Forecast |
|---|---|---|---|
| 1 | `feat/play-journal-attachments-1-api` | T1–T3 | ~1,100–1,400 (domain and validation ~300, HTTP and API tests ~350, Behat ~80, generated `openapi.json` / `schema.d.ts` ~150–400, frontend fixtures and type fallout ~100, doc ~150) |
| 2 | `feat/play-journal-attachments-2-ui` | T7, T4–T6 | ~1,000–1,400 (slice 1 review fixes ~100, picker ~300, folding and note rendering ~300, Vitest ~300, e2e ~80, stories ~100, glossary and ADR 0016 note ~50) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc | parent doc, writer commits (delegated writer, direct) | [x] | `d4db0fb` |
| T2 | 1 | Domain and application: `NoteContent` attachments (ordered ids, `fromArray` legacy `[]`), attachment rules in `CampaignJournal` (same campaign and scene, attachable kind, no random event, no duplicates, ≤ 20) with a domain error, `RecordNote` carries the ids; unit tests | delegated writer, direct | [x] | `5c0634b` |
| T3 | 1 | HTTP and contract: `RecordNoteRequest.attachments` (optional, nullable), 422 mapping, `NoteContentResponse.attachments`, API tests (every 422 case, round trip, legacy note), Behat scenario, `make api`, frontend fixtures and type fallout | delegated writer, direct | [x] | `5bf6eb7` |
| T7 | 2 | Slice 1 review fixes: unit and API tests attaching a `check` and a `likelihood` result; `NoteContent::fromArray` fails with `InvalidJournalEntryContent` (not `InvalidNoteAttachment`) on a malformed stored note; the 21-ids API test asserts its real message | delegated writer, direct | [x] | |
| T4 | 2 | `NoteComposer` attachment picker for the current scene's attachable results; `useRecordNote` sends `attachments`; Vitest; stories | delegated writer, direct | [x] | |
| T5 | 2 | Journal folding: attached entries hidden from their place and rendered inside their note (and in `FocusPanel` if it lists scene entries); Vitest; e2e in `play-session.spec.ts` | delegated writer, direct | [x] | |
| T6 | 2 | Docs: glossary JournalEntry kinds and attachments; ADR 0016 note on folded results | | [ ] | |

## Progress

- 2026-10-20: feature started; journal code mapped by a read-only explorer; display (fold under the note) and attachable kinds decided by the user; 2 slices.
- 2026-10-20: T1 feature doc committed by the writer (doc-only, no test applicable).
- 2026-10-20: T2 done: RED = fatal on missing `InvalidNoteAttachment`/`attachments` with src stashed; GREEN = `tests/Unit/Play` 1622 OK. Validation uses `ofId` per id (no new port method); stored-shape expectations updated incl. `JournalEntryRepositoryContract`.
- 2026-10-20: T3 done: API/Behat tests written after the implementation (passed on first full run, no RED observed); JournalApiTest 90 OK, Integration/Play 998 OK, Behat 180 scenarios OK, `make qa` OK, `make api` regenerated; malformed `attachments` (not a list of strings) answers 422; Vitest first-render timeouts rerun per file: 188 OK.
- 2026-10-20: T7 done: RED = 3 `NoteContentTest` malformed-stored-note cases threw `InvalidNoteAttachment` before the fix; GREEN = `tests/Unit/Play` + `JournalApiTest` + `JournalChecksApiTest` 1749 OK. The check and likelihood attachment tests passed first time (the rules were already generic).
- 2026-10-20: T4 done: `NoteComposer` takes `current` and reads the journal itself (`useJournal`); `useRecordNote` already passed the request body through, so it needed no change. Picks are derived against the current scene's attachable results, so a scene change drops them. RED/GREEN: tests written with the implementation, one wrong-expectation fix, no separate RED; NoteComposer + CampaignPage tests OK.
- 2026-10-20: T5 done: `isAttached` hides folded entries in `Journal` (emptiness check included) and `FocusPanel`; `NoteAttachments` renders them in the note's order through `JournalEntryView` children and `RandomEventOffer`, so an attached fate question keeps its event (and its roll button in the current scene). Tests written with the implementation (attachments helpers passed first run; one brittle assertion fixed); journal + FocusPanel Vitest 120 OK; e2e runs with the final checks. An attached table result no longer offers "Switch to <Scene Type>" (the offer stays on entries listed in place).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-a4a3b0353ee01c3e` | `d4db0fb`..`5bf6eb7` | Approved, acknowledged. Non-blocking R3: no test attaches a `check` (unit test lacks a likelihood); `NoteContent::fromArray` re-applies write rules on read and can throw `InvalidNoteAttachment`; the 21-ids API case has an empty needle. Fixed in slice 2 (T7) | #232 (merged) |

## Next step

Slice 2 (T7, T4–T6) on `feat/play-journal-attachments-2-ui`; then native review, PR `Closes #231`, last-slice doc commit.
