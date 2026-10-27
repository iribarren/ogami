# 0045. Gear, inventories, a currency and shops

- **Status:** Accepted, amends [0025](0025-items-and-shops-after-the-mvp.md), [0027](0027-sheet-effects.md) and [0035](0035-campaign-release-upgrade.md)
- **Date:** 2026-10-27

## Context

[ADR 0025](0025-items-and-shops-after-the-mvp.md) deferred items, inventory, a currency and a `shop` step kind to `play-items-economy`, to be started when a preset needs them. `preset-cpr-campaign` (roadmap B12, [example 4](../domain/flow-examples.md#4-cyberpunk-red-campaign-in-acts)) needs them:

- **Gear has no price, quantity or slot.** It is a sheet `list` of strings ([ADR 0022](0022-character-sheets-in-schema-version-2.md)). Example 4's Night Market needs one `choice` per price, so a market of a dozen stalls cannot be authored.
- **Money has no home on the Character.** Example 4 keeps `eddies` in a campaign tracker as an M2 workaround, and `night-job` pays out into a text fact slot.
- **A spend can be taken with no money.** Sheet effects clamp at a field's `min` ([ADR 0027](0027-sheet-effects.md)), so "Pay the rent" always succeeds.
- **Running campaigns lose gear on upgrade.** B12 prefers a second Flow in `night-job`, whose `gear` and `cyberware` are `list` fields. The upgrade planner ([ADR 0035](0035-campaign-release-upgrade.md)) resets a field whose type changed.

The user decided four points: the currency is per Character, a sheet `number` field; a voluntary spend the Character cannot afford is refused; a shop's stock is an offer plus an optional random count rolled when the step is reached; gear changes only through shops and the player's sheet edits.

## Decision

### Contract: additive in schema version 2

Every new part is optional. No release valid before uses them, so every release keeps its content hash; `null`, an empty `gear` list and default values are left out of the canonical form. Play's translator refuses each new shape, with its path and a message naming this ADR, until the slice that plays it (precedent: ADR 0027, 0040, 0041, 0044). A release using them publishes but cannot start a Campaign until then.

```
gear        [{ key, name, cost, slots?, category?, description? }]       0..500, release level
sheet       { …, currency?: { field, unit? } }
inventory   field type { capacity?, maxStacks?, categories? }
shop        step kind  { common…, offer, stock?, sellRate? }
```

**Gear catalog.** `key` follows the key rule and is unique. `name` is 1–60 characters, unique trimmed and ignoring case, so an upgrade can match a list string to one entry. `cost` is an integer 0..1,000,000 in the currency. `slots` is the space one unit takes, 0..100, default 1 (left out of the canonical form). `category` is a label of 1–40 characters; there is no category list, and shops and inventories match it exactly. `description` is at most 500 characters.

**Currency.** `sheet.currency.field` names a `number` field of the sheet, never a `derived` one; a sheet has at most one currency. `unit` is 1–10 characters shown after amounts ("eb"). The floor is the field's `min`, else 0. Sheet effects, formulas, conditions in sheet scope and the upgrade planner already handle a number field, so the currency needs nothing new there. Money above the field's `max` clamps as sheet effects do; a currency field usually declares no `max`.

**Inventory field.** `capacity` is the slots it holds, 1..999 (absent: no slot limit). `maxStacks` is 1..100, default 50 (left out of the canonical form). `categories` lists 1–20 distinct labels it accepts, each the category of at least one catalog entry; absent, it accepts every entry, with or without a category. A value is a list of stacks, empty at first:

- a catalog stack `{gear, quantity}`: a catalog key the field accepts, at most once per field;
- a free stack `{name, quantity, slots}`: a name of 1–200 characters, trimmed, and slots 0..100 per unit.

`quantity` is 1..9,999. The used slots, the sum of quantity × slots, stay within `capacity`. An inventory is not a sheet effect target and formulas cannot read it.

**Shop step.** The common step fields without `mandatory`, plus:

- `offer` `{gear?, categories?}`: up to 100 distinct catalog keys and up to 20 distinct categories, at least one entry in all. Each key exists, each category is the category of at least one entry. The offer resolves to the union, in catalog order.
- `stock`: an integer 1..50 or a `{dice}`. That many distinct entries of the offer are on sale. An integer above the resolved offer's size is a publisher error; a dice roll clamps to 0..that size, and 0 leaves nothing on sale (the player can still sell). Without `stock` the whole offer is on sale. Stock limits which entries are on sale, not how many units.
- `sellRate`: an integer 1..100, the percent of `cost` the shop pays per unit sold, `floor(cost × sellRate / 100)`. Absent, the shop does not buy.

Publisher rules beside the shapes: every offered entry is accepted by at least one `inventory` field; a release with a `shop` step declares `sheet.currency`; a shop never sits in a Scene Type's `setup` list, which has no Character. The step's own `effects` refuse sheet effects: the transaction is the shop's only sheet change, so it stays all or nothing with one journal entry. A shop step is never skipped: leaving with an empty transaction is how a player passes it, so `mandatory` is refused.

### Play

- **Inventories** live in Character values as the stack lists above, checked against the pinned sheet and catalog on every write. Characters stored before read unchanged.
- **Stock roll.** When the FlowRun reaches a shop step with `stock`, the server draws the entries, uniformly and without replacement, with the injected random source, and stores them on the FlowRun, as a `PendingCheck` is stored. They stay fixed until the step completes and are listed in catalog order. A FlowRun stored before this feature has none.
- **Transaction.** Completing the step sends `{characterId, buy: [{gear, quantity}], sell: [{field, gear, quantity}]}`; a Character is required. Sells apply before buys, so their money and freed slots count for the buys. Only catalog stacks are sold, never free stacks. A purchase lands on the Character's stack of that gear in the first accepting field holding one, else in the first `inventory` field, in sheet order, that accepts its category; it never spills into another field. The whole transaction is refused, and nothing changes, on unknown or unstocked gear, a sell the shop does not buy, an over-sell, money that would go below the floor (`insufficient_funds`) or an inventory over `capacity` or `maxStacks` (`inventory_full`). An empty transaction completes the step: the player leaves. The step's `effects` then apply as on any step.
- **Placeholder.** `{step:key}` reads the names of the gear bought, comma-joined, in request order; empty when nothing was bought.
- **Journal.** One entry: the Character, the bought and sold lines with their unit prices, and the currency before and after.
- **Affordability (amends [ADR 0027](0027-sheet-effects.md)).** A sheet effect on the currency field that would take it below the floor is refused with `insufficient_funds` when it is voluntary: a `choice` option's, a `prompt`'s or a `character` step's effects. The step does not complete. Check outcomes are involuntary and still clamp, and so do push costs, which keep the check rules of [ADR 0041](0041-dice-pools-pushes-and-conditions.md).
- **Error codes** ([ADR 0037](0037-api-error-codes.md)): `insufficient_funds` and `inventory_full` are `{error, code}` bodies with status 422. Play answers 422 when a request breaks a rule of the sheet given the Character's values, as `point_budget_exceeded` does, and 409 for concurrent writes and for a flow in the wrong state.

### Upgrade (amends [ADR 0035](0035-campaign-release-upgrade.md))

A new change kind, `converted`, for a field that keeps its key and changes type between `list` and `inventory`:

- **`list` → `inventory`.** Each string matching a catalog name (trimmed, ignoring case) that the field accepts becomes `{gear, quantity: 1}`, duplicates adding up; any other string becomes a free stack `{name, quantity: 1, slots: 0}`. Then fit.
- **`inventory` → `inventory`.** A stack whose gear is gone, or no longer accepted, becomes a free stack with its last name and slots. Then fit.
- **`inventory` → `list`.** Each stack becomes its name, `"<name> ×<n>"` when the quantity is above 1. Then `maxItems` as today.
- **Fit.** Stacks beyond `maxStacks` or `capacity` are dropped from the end (`trimmed`). Any other type change still resets the value.

The plan stays deterministic and fingerprinted.

### Studio

- **Gear catalog:** `PUT` and `DELETE /api/studio/game-systems/{key}/gear/{gear}`, in the style of point budgets ([ADR 0043](0043-several-point-budgets.md)). A delete is refused while a shop offers the entry by key (`DraftReferences`).
- **Currency:** `PUT` and `DELETE …/sheet/currency`. Deleting or retyping the currency field is refused while it is the currency.
- **Inventory fields** are saved through the existing free-form field route; shop steps through the Scene Type and Flow documents.
- A category renamed away from a shop's or an inventory's categories is a publisher error (the item rules), not a guard.

### Naming: Gear, not item

The contract key is `gear`, the field type `inventory`, and an inventory holds *stacks*. Play already says item for NPCs, Threads and Characters (`ItemKind`, `PickedItem`, `ItemCount`, `{picked}`), and Studio for library items and item rules. A second meaning would make every one of them ambiguous.

## Consequences

- A preset can price, count and slot gear, and a Night Market is one step instead of one choice per price.
- Money is a sheet number, so formulas, conditions and checks read it with no new reference kind.
- A spend the player chooses is refused when the Character cannot afford it; a consequence of a roll still clamps. Authors keep check outcomes for unavoidable losses.
- `night-job` can move its `gear` and `cyberware` lists to inventories in a new release without running campaigns losing them.
- The release grows by a catalog of up to 500 entries; Studio edits it entry by entry.

## Out of scope

Crafting; encumbrance beyond slots; flow effects that give or take gear; services, bought and not kept; formulas or conditions that read an inventory; gear in library packs; per-entry stock counts; several currencies. Each is additive later.
