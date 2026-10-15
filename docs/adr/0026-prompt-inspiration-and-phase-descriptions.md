# 0026. Prompt inspiration tables and phase descriptions

- **Status:** Accepted
- **Date:** 2026-10-15

## Context

Playtest 1 of `preset-real-system` found two gaps in the guided flow:

- Table rolls write facts directly. A `table` step rolls and records the result as the answer, so the player reads a random noun instead of writing a fact. The player should write the fact and use the tables as **inspiration**.
- Ending a phase lacks an explanation. The end-phase confirmation says nothing about what the phase was for or when it is done, so a novice cannot tell whether to end it.

## Decision

Both are additive optional fields of schema version 2.

- **`inspiration` on a `prompt` step:** 1 to 5 keys of oracle tables of the release (not likelihood oracles), unique. They are suggestions, not rolls of the step: no entry effects run and the flow's rolled-table checks do not count them. Play shows one roll button per table in the step card; a roll goes through the Oracle panel's table roll (a journal entry) and its result shows in the card. The player still writes the answer.
- **`description` on a phase:** optional text, up to 2,000 characters. Play shows the current phase's description under the phase in the guided panel and in the end-phase confirmation.
- **Validation:** the validator refuses an unknown table, a duplicate, an empty list and more than five entries, as the JSON Schema does for the shape.

## Consequences

- Existing releases and presets stay valid with the same content hash: absent fields are dropped from the canonical form.
- A preset can replace table-to-fact steps with prompts that offer tables as inspiration (`preset-real-system` slice 5).
- A step has no way to force a roll; inspiration is always optional.
