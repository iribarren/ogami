# 0048. Select sheet fields

- **Status:** Accepted
- **Date:** 2026-10-31

## Context

A sheet had no field for a single choice from a fixed list: a stance, an origin, a class. Authors used a `text` field (free typing, no validation) or a `conditions` field (a set, not one pick). Games with a closed list of values per Character need one option, validated.

## Decision

- **A new sheet field type `select`** `{key, label, options, default?}`, in schema version 2 without a version bump. `options` are 1 to 50 `{key, label}` (the conditions option shape and key rules), keys unique in the field. `default`, when given, is the key of one option; the domain refuses anything else.
- **The value is one option key or null** (unset). A new Character starts with `default`, or null without one. Play refuses a value that is neither null nor a declared option key (422).
- **Not readable by formulas, not changed by effects, not in point budgets.** A formula needs a number and a select has none; a sheet effect changes number, track and conditions fields only. Studio refuses both with the usual field-type message. `startMax` and point budgets already accept number fields only.
- **Release upgrade.** A chosen option the new release no longer declares resets to the new `default`, or null, and is reported as a reset like other resets. A kept option is unchanged. A field whose type changes resets as today.
- **Legacy Characters** have no value for a new select field and read it as null.

## Consequences

- Authors get a validated single choice; players see a select control with an unset entry.
- Every layer that switches over field types gains one arm (contract, Studio, translator, values, planner, API, both UIs).
- A select value cannot drive a roll or a derived value. Reading it in formulas would need a mapping from options to numbers and is left out.

## Out of scope

- Options carrying numeric values, or formulas reading the chosen option.
- Effects that set a select field.
- Multi-select (the `conditions` field is the set type).
