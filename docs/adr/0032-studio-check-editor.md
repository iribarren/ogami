# 0032. Studio check editor and test-roll sandbox

- **Status:** Accepted
- **Date:** 2026-10-17

## Context

A schema 2 GameSystem draft ([ADR 0028](0028-studio-gamesystem-drafts.md)) can only get its `checks` from a copied or restored release. The `studio-check-editor` feature lets a `GAME_MANAGER` author them: dice notation, a modifier formula, an optional difficulty and outcome bands ([ADR 0024](0024-structured-checks-without-scripting.md)), and try a check before publishing. Checks are named only by `check` steps, whose outcomes name their bands, so renaming a check or a band can silently break a flow; today that surfaces only through the item rules, with a single path. Play evaluates a check in its own Domain, which Studio cannot import; only Randomness and Formula are shared kernel.

## Decision

- **Contract unchanged.** The check shape, its rules and the canonical form of every release, hash included, stay as ADR 0024 has them. The publisher's item rules decide what a check may be, in the editor as in publishing.
- **Per-item commands**, in the style of [ADR 0030](0030-studio-flow-editor.md): save a check (create, or replace by its original key; a different key renames it), delete a check, move a check to a position of the whole list. Each carries the `expectedVersion` the client read (a mismatch is a conflict), runs the item rules on the whole draft, is all-or-nothing, and keeps a replaced check in place while a new one is appended. A schema version 1 draft refuses them. A draft with no `checks` is treated as having none.
- **Key collision:** a key used by another check is a conflict (409); an unknown key is a 404; a move index out of range is a 422 on `index`.
- **References.** The draft reference scan gains check keys (`step.check` of `check` steps in Scene Type parts and flow phase hooks) and band keys (`step.outcomes[].band` of the steps naming that check). Renaming or deleting a check that a step names is refused (422) listing every path. A save that drops a band key an outcome names is refused the same way, listing the outcome paths; a band rename is a drop plus an add, with no cascade. Other breaking edits, such as dropping the difficulty a step sets, fail through the item rules with the step's path. Unreferenced checks and bands change freely.
- **Dice are validated on save.** The server's 422 on `checks[i].dice` is the validation; there is no TypeScript dice parser.
- **Test roll.** A Studio endpoint rolls a **saved** check by key against sample values (number and track fields, trackers, difficulty, situational modifier), never records anything and never changes the draft. Studio composes the shared-kernel dice expression and formula evaluation itself, in Play's order (difficulty, situational, modifier, bounds, roll, overflow guard, band), with an injected random source; its unit tests mirror Play's check cases so the two agree. Derived values are computed from the saved sheet. A missing number sample reads the number's default, else 0 moved inside its min..max (Play's rule for a Character with no value); a track takes 0, and a track without a fixed max accepts 0..999 and reads its sample as its max; a missing tracker its initial value (a clock 0), and tracker samples must lie within the tracker's range. Evaluation failures (division by zero, overflow) refuse the roll and roll nothing, as in Play. The editor saves unsaved edits first.
- **Sandbox samples** are shared with the sheet builder's preview: one set of sample field and tracker values per draft, lifted to the page. Band bounds are shown live from the TypeScript formula kernel, with a warning when formula bounds are not increasing for the samples.

## Consequences

- Checks never become invalid through the editor, so publish does not trip on them.
- Renaming or deleting a check, or changing its band keys, means editing the steps that use it first (in the flow editor); the error says where they are.
- The test roll duplicates Play's evaluation order in a small Studio service instead of moving it to the shared kernel; the mirrored unit tests are the guard against drift.
- Studio depends on Randomness and Formula only through the shared kernel; Play is untouched.
- Exploding dice, pools, success counting, opposed rolls and re-rolls remain ADR 0024 gaps; check steps are edited by the flow editor.
