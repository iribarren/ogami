# Feature: play-campaign-facts

- **Locator:** `odd/tasks/play-campaign-facts.md` · Engram topic `odd/play-campaign-facts/tasks`
- **Issue:** #75 · **Current branch:** `feat/play-campaign-facts-6-ui` (from `main` `1680718`) · **Status:** slice 6 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-threads-npcs` (PRs #64–#74)

## Objective

Campaign facts, fact slot hints, the `fillFact` effect and `{fact:key}` placeholders, as decided by [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) §6 and [ADR 0018](../../docs/adr/0018-narrativeflow-control-flow-and-cast.md) (decisions 9, 17, 20, 21). Roadmap feature 8b (M2).

## Problem and why

Worldbuilding output (the city, the Prince, a faction, the character's ambition) gets buried in the journal. Flows need it too: Session Zero fills slots, later prompts quote them (`{fact:ambition}`), and session hooks refill short-term goals. Releases already declare fact slots (`{key, label, type}`, feature 7) but nothing fills them; four of the five example fixtures wait for this feature.

## Scope

- **In:** fact slot `hint`; effect `fillFact`; placeholder `{fact:key}`; the 8b parts of the example fixtures (examples README); Play ACL; Campaign facts (free facts, slot facts, links) with persistence, HTTP and a Facts panel beside the journal; an `npc` / `thread` slot filled by hand picks an existing item or creates one; NPC and Thread rows show their linked facts; FlowRun applies `fillFact` and renders `{fact:key}`; the step card shows the slots a step fills, with their hints.
- **Out:** mentions and backlinks from journal entries; deleting facts or clearing a slot (as NPCs and Threads, items are never deleted in M2); carrying facts to another campaign (`play-campaign-transfer`); player trackers linked to facts.
- **Factions, disposition, goals** need no new fields (ADR 0018 decisions 20, 21): a faction is a free fact plus a `leader` NPC; disposition is a fact linked to an NPC; sandbox goals are Threads in `thread` slots.

## Constraints

- Schema version 2 is extended **additively**: every release valid today stays valid and reads the same. Never edit `v1.schema.json`. The JSON Schema and the domain validator stay in agreement (`ReleaseSchemaAgreementTest`).
- Dependency rule and PHPat ([ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)): facts live in framework-free `Play\Domain`; the translator in `Play\Infrastructure\GameSystem`; Studio validation in `Studio\Domain\Release\Version2`.
- ADR 0007: facts are a JSONB column on the Campaign, under its optimistic lock (version → 409).
- Links and slot values are references by id; names and labels are read when shown, so a rename shows everywhere.
- Glossary terms in code, UI and docs: Campaign fact, Fact slot, Free fact, Fact link, `fillFact`, Effect.
- Every slice is safe on `main` alone: backend unused until its UI lands.

## Contract: schema version 2 additions

```text
FactSlot     + hint?                     (≤500, string or null, like tracker and tag hints)
Effect kind  fillFact                    new
Placeholder  {fact:key}                  new
```

| Part | Shape | Rules |
|---|---|---|
| FactSlot | `{key, label (1–100), type: text \| npc \| thread, hint? (≤500)}` | Unchanged otherwise (≤100 slots, unique keys) |
| `fillFact` | `{kind: fillFact, slot: key, text? (1–2000)}` | `slot` names a declared fact slot. `text` is placeholder text and is allowed on a `text` slot only (on `npc` / `thread` slots it fails: the value is the picked item). Omitted `text` on a `text` slot means `{answer}` |
| `{fact:key}` | Any placeholder text (step `title`, `prompt`, `tip`; effect text, including `fillFact` `text`) | `key` names a declared fact slot of the release; `{fact}` without a key fails |

Effects keep the limit of 10 per list and may appear wherever effects do (step `effects`, bands, branches, options, `otherwise`, table entries). Canonical form: a `null` hint is omitted, as for trackers and tags.

## Play rules

### Campaign facts

| Field | Free fact | Slot fact |
|---|---|---|
| `id` | UUID | UUID |
| `slot` | — | slot key of the pinned release; at most one fact per slot |
| `label` | 1–100, set by the player | — (the slot's `label` is shown) |
| `text` | 1–2000 | `text` slot: 1–2000; `npc` / `thread` slot: — |
| `subject` | — | `npc` slot: an NPC id; `thread` slot: a Thread id; `text` slot: — |
| `links` | `[{kind: npc \| thread \| fact, id}]`, ≤20, unique, never the fact itself | same |

- At most 500 facts per campaign. Listed slot facts first (release slot order), then free facts in creation order.
- The player adds free facts, fills a slot (text, or an existing NPC / Thread, or a new one by name: the command creates an open weight-1 NPC / Thread with no tags and fills the slot with it, all or nothing), refills a slot (replaces its value, keeps its links), edits a free fact's label and text, and edits any fact's links. Closed items may be subjects and link targets.
- Refusals, all or nothing: unknown slot key, text on an `npc` / `thread` slot or a subject on a `text` slot, a missing value (422); a link or subject naming an unknown NPC, Thread or fact (404); a duplicate link or a self link (422); the 500 limit (409, as for NPCs); an unpinned or unreadable release (as for NPCs).
- The value of a fact (shown in the panel, rendered by `{fact:key}`): the text, or the subject's current name. An unfilled slot renders empty.
- An NPC or Thread shows its linked facts: the facts whose `links` name it, and the slot facts whose `subject` is it.

### Flow behaviour

- **`fillFact`** applies in effect order, like other effects:
  - `text` slot: renders `text` (default `{answer}`), trimmed and cut to 2000; empty → nothing changes.
  - `npc` / `thread` slot: the first item of that kind in the current `{picked}` (so `createNpc` then `fillFact prince` fills it with the new NPC); none → nothing changes.
  - A filled slot is replaced (its links stay); an unfilled one gets a new slot fact with a name-based id derived from the campaign id and its fact count (as `GeneratedItemId`); at 500 facts nothing is created. A generated id already held fails closed (`FactAlreadyExists`, 409) and nothing changes.
  - No journal entry of its own: the step's entry already records the answer.
- **`{fact:key}`:** the slot fact's value, read when rendered (a rename shows); empty when unfilled.
- **Step card:** a step view lists the slots its effects may fill (`fills: [{key, label, type, hint}]`, from every effect list of the step, unique, release order); the card shows them with their hints.

## Slice plan

Forecasts include tests, docs and generated files (OpenAPI spec, TS types). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/play-campaign-facts-1-contract-acl` | `feat(studio): play-campaign-facts 1/5 contract-acl` | T1, T2 | ~1,400 |
| 2 | `feat/play-campaign-facts-2-facts` | `feat(play): play-campaign-facts 2/5 facts` | T3 | ~1,600 |
| 3 | `feat/play-campaign-facts-3-facts-http` | `feat(play): play-campaign-facts 3/6 facts-http` | T4a | ~1,600 |
| 4 | `feat/play-campaign-facts-4-fact-slots-http` | `feat(play): play-campaign-facts 4/6 fact-slots-http` | T4b (+ slice 3 review fixes) | ~1,450 |
| 5 | `feat/play-campaign-facts-5-flow` | `feat(play): play-campaign-facts 5/6 flow` | T5 | ~1,400 |
| 6 | `feat/play-campaign-facts-6-ui` | `feat(play): play-campaign-facts 6/6 ui` | T6, T7 | ~1,700 |

Slice 3 was split before its write (2026-10-12): T4 as one slice forecast ~2,200 lines (three endpoints, the read model, API tests, Behat, generated files), past the ceiling. Slices 1 and 2 shipped as `n/5`. Slice 3 shipped at 2,590 lines (size exception approved by the user, reviewed as two candidates).

Safe on `main`: slice 1 lets Studio accept the new parts and Play read them; releases are published only from `backend/presets/` and none uses them; FlowRun ignores `fillFact` and renders `{fact:key}` empty until slice 5. Slices 2–5 add unused backend (the campaign response gains `factSlots` and `facts`, the step view `fills`, ignored by the SPA); slice 6 shows it.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc. `v2.schema.json`: fact slot `hint`, effect `fillFact`. Studio `Version2`: slot hint, `fillFact` (slot exists, `text` only on `text` slots), `{fact:key}`. Contract doc v2 additions. Valid and invalid fixtures; unit tests | delegated writer (slice 1, 4+ non-trivial files) | [x] | `da655e4` |
| T2 | 1 | Play ACL: `FactSlot` hint, `FillFactEffect`, typed and framework-free; FlowRun ignores `fillFact` and Play `Placeholders` renders `{fact:key}` empty until slice 5; the four example fixtures gain their 8b parts (examples README); translator tests | delegated writer (slice 1, 4+ non-trivial files) | [x] | `d4ae473`, `a0f5122` |
| T3 | 2 | Campaign facts (domain: free facts, fill and refill a slot by hand incl. creating an NPC / Thread, edit label, text, links, limits, all or nothing); JSONB column, Doctrine type, migration; repository round trip incl. legacy rows | delegated writer (slice 2, 4+ non-trivial files) | [x] | `6510d64`, `346f3db` |
| T4a | 3 | Campaign response gains `factSlots` and `facts` (values and link names resolved); commands, handlers and endpoints to add a free fact and edit a fact (free: label, text, links; slot: links); API tests (400/404/409/422), Behat; `make api` | delegated writer (slice 3, 4+ non-trivial files) | [x] | `3fed407`, `0692c2c`, `b1b277c`, `421836a` |
| T4b | 4 | Fill / refill a slot over HTTP (text, existing NPC / Thread, new one by name): command, handler, endpoint; editing a slot fact's links end to end; API tests (400/404/409/422), Behat; `make api` | delegated writer (slice 4, 4+ non-trivial files) | [x] | `c2a9b6e`, `ac547df`, `073b648` |
| T5 | 5 | FlowRun: `fillFact` (text, `{picked}` NPC / Thread, refill, limit, generated id), `{fact:key}` in views and effect text (an unknown key fails closed: slice 1 review), step view `fills` and its HTTP field; unit, integration and API tests; `make api` | delegated writer (slice 5, 4+ non-trivial files) | [x] | `70af06e`, `58e3140`, `bb98bec` |
| T6 | 6 | UI: Facts panel beside the journal (slots with hints, fill by text or pick / create, free facts, edit, links), linked facts on NPC and Thread rows, slots a step fills on the step card; tests and stories | | [ ] | |
| T7 | 6 | Glossary and contract doc wrap-up; e2e smoke for facts | | [ ] | |

## Progress

- 2026-10-12: feature started; doc and slice plan written; issue #75.
- 2026-10-12: slice 1 — T1 done: schema, Studio validation (slot hint, `fillFact`, `{fact:key}`), contract doc, fixtures and tests; RED observed first.
- 2026-10-12: slice 1 — T2 done: Play ACL (`FactSlot` hint, `FillFactEffect`), FlowRun ignores `fillFact`, `{fact:key}` renders empty; the four example fixtures gain their 8b parts and Play reads all five.
- 2026-10-12: slice 2 — T3 domain: `Facts` namespace (`Fact`, `FactLink`, `FactFill`), Campaign add / fill / edit / read facts, `reconstitute()` checks; unit tests (RED first).
- 2026-10-12: slice 2 — T3 persistence: `facts` JSONB column and migration, strict `CampaignFactsType` (fails closed), `assertFactsConsistent()` after load; repository round trip, legacy row and malformed-row tests.
- 2026-10-12: slice 3 — slice 2 review test: refilling a filled `npc` / `thread` slot by name keeps the fact id and links and creates the item (passed at once: the domain was right).
- 2026-10-12: slice 3 — T4a read model: campaign response gains `factSlots` and `facts` (values and link names resolved, slot label for slot facts); handler unit tests and API tests (new campaign, values, rename); `make api`; frontend fixtures gain empty lists.
- 2026-10-12: slice 3 — T4a free fact endpoints: `AddFact` / `EditFact` with handlers, `FactIdGenerator` port and UUID v7 adapter, `CampaignFactsController` (`POST /api/campaigns/{id}/facts`, `PUT .../facts/{factId}`); a free fact edit needs label and text (handler), a slot fact takes only links (domain); handler unit tests, API tests (400/401/403/404/409/415/422, concurrent save), Behat `facts.feature`; `make api`.
- 2026-10-12: slice 4 — slice 3 review fixes: a JSON object as `links` is a 400, unreadable-release API tests for the fact endpoints, `tryFrom` for link kinds (`InvalidFact`), guarded post-save read, `GetCampaignHandler` fail-closed tests (a slot the release lacks, on a fact and on a link target; dangling links are refused earlier by `reconstitute()`), `UuidFactIdGenerator` test. RED observed for the `{}` links and link kind fixes; the other tests passed at once (the code was right).
- 2026-10-12: slice 4 — T4b fill a slot: `FillFactSlot` and handler (text, existing subject, or a new NPC / Thread by name; exactly one value; ids from the generators), `PUT /api/campaigns/{id}/fact-slots/{slot}` (`fillFactSlot`, 200 for a first fill and a refill; 400/401/403/404/409/415/422, unreadable release 500); handler unit tests, API tests incl. concurrent save, editing a slot fact's links end to end, Behat scenarios. Handler and API tests were written with the code, so RED was not observed separately except a list body (`[3]`) that first answered 422 instead of 400.
- 2026-10-12: slice 4 — `make api`: OpenAPI spec and TS types gain `fillFactSlot` and `FillFactSlotRequest`.
- 2026-10-12: slice 5 — slice 4 review fixes: API tests for `links: {}` on a fact edit (400, links kept) and for the guarded read after a save (release gone → 500, fact saved); `FillFactSlotHandlerTest` keeps a deep snapshot and covers a refused refill. All passed at once (the code was right).
- 2026-10-12: slice 5 — T5 FlowRun facts: `fillFact` applies in effect order (text slot: rendered `text`, default `{answer}`, cut to 2000; `npc` / `thread` slot: first picked item of its kind; refill keeps id and links; 500 limit adds none; a held generated fact id is `FactAlreadyExists`, 409, all or nothing), `{fact:key}` reads the slot value when rendered and an unknown key fails closed, step view `fills` (`StepEffects` walks step, option, band, branch, `otherwise`, pick outcome and table entry effects); unit, handler and API tests. RED observed first (placeholder and FlowRun unit tests); the handler and API tests were written with the code.
- 2026-10-12: slice 5 — `make api`: OpenAPI spec and TS types gain the step `fills` (`FactSlotResponse` list); the flow step fixture gains `fills: []`.
- 2026-10-12: slice 6 — slice 5 review fixes: a text-slot `fillFact` without `text` and without an answer changes nothing (guard added; the test passed at once, because a blank pick answer already rendered empty and a null answer is unreachable); `Placeholders` takes `facts` as a required constructor argument, callers updated.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-c4fd077e3ac7057e` | `da655e4`, `d4ae473`, `a0f5122` | Approved. One non-blocking suggestion, to fix in slice 5: Play `Placeholders` renders any `{fact:key}` empty without checking that the key names a fact slot of the pinned release (`Placeholders.php:53`), unlike `{tracker:key}` and `fillFact` slots; an unknown key should fail closed (translator or render time), with a test | [#76](https://github.com/iribarren/ogami/pull/76) |
| 2 | `review-20cd4a5e879f8397` | `6510d64`, `346f3db` | Approved. One non-blocking suggestion, fixed in slice 3: test that refilling a filled `npc` / `thread` slot with a new item by name keeps the fact id and links (`Campaign.php:800-803`) | [#77](https://github.com/iribarren/ogami/pull/77) |
| 3 | `review-764b38d1ef4e9686` (`3fed407`..`0692c2c`), `review-ed3087f7f6c5f028` (`b1b277c`..`421836a`) | `3fed407`, `0692c2c`, `b1b277c`, `421836a` | Approved (two candidates). Non-blocking findings, fixed in slice 4: a JSON object `{}` as `links` decodes to an empty list and clears the links instead of a 400 (`CampaignFactsController.php:150`); no API test for an unreadable pinned release on the fact endpoints; `AddFactHandler::links` uses `FactLinkKind::from` (undeclared `ValueError` outside HTTP); the post-save `GetCampaign` read is unguarded (a failure there is a 500 after a save); `GetCampaignHandler` fail-closed branches lack unit tests; `UuidFactIdGenerator` lacks a test | [#78](https://github.com/iribarren/ogami/pull/78) |
| 4 | `review-f9fc9e02267551c2` | `c2a9b6e`, `ac547df`, `073b648` | Approved. Non-blocking suggestions, to fix in slice 5: no `PUT /facts/{factId}` test proves that `links: {}` is a 400 and keeps the links (`CampaignFactsController.php:129`); `FillFactSlotHandlerTest` keeps the starting state with a shallow `clone` in the refused-fill test and never covers a refused refill of an already-filled slot (`FillFactSlotHandlerTest.php:118-119`); no test reaches the guarded read after a successful save (`CampaignFactsController.php:224-230`) | [#79](https://github.com/iribarren/ogami/pull/79) |
| 5 | `review-5e97df0b3b5fc33e` | `70af06e`, `58e3140`, `bb98bec` | Approved. Non-blocking warnings, to fix in slice 6: a `text` slot `fillFact` without `text` on a step with no answer fills the slot with the empty-answer placeholder instead of changing nothing (`FlowRun.php:969-971`); Play `Placeholders` defaults `facts` to `[]`, so a caller that omits it throws on every `{fact:key}` (make it required) | [#80](https://github.com/iribarren/ogami/pull/80) |

## Next step

Slice 6 (T6, T7) is being written on `feat/play-campaign-facts-6-ui`: slice 5 review fixes first, then the Facts panel, linked facts on NPC and Thread rows, the step card's slots, then the glossary and contract wrap-up and the e2e smoke. Then review and the last PR (`Closes #75`).
