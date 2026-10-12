# 0019. Random events from fate-question doubles

- **Status:** Accepted
- **Date:** 2026-10-12

## Context

The [vision](../vision.md) left one open question for feature `preset-mythic-flow`: do fate-question doubles trigger random events? In Mythic-style play, a fate question rolled on 1d100 that comes up as a double (11, 22 … 99) can mean an unexpected turn, when the double's digit is at most the current chaos factor. Without a rule, random events only come from interrupted scenes.

Fate questions are likelihood oracles ([ADR 0017](0017-narrativeflow-model.md)). Reusable procedures that run flow steps from an oracle roll stay deferred (vision open question), so the answer cannot start a flow branch by itself.

## Decision

A random event on a fate-question double is a declarative rule on the likelihood oracle, not a flow procedure.

- **Release (schema version 2, additive):** a likelihood oracle may carry `"randomEvent": {"table": "<oracle table key>"}`. The table exists in the release, the oracle has `chaos`, and `sides` is 100 (doubles need two digits). Unknown fields fail closed.
- **Trigger:** the roll is below 100, a multiple of 11 (a double) and its digit (`roll / 11`) is at most the chaos factor used for the answer (the neutral factor when none was given).
- **Randomness:** `LikelihoodOracle` parses the rule; `LikelihoodAnswer::randomEvent()` returns the table key when the roll triggered one, else null.
- **Play:** the anti-corruption layer carries the rule into the snapshot. The journal entry and the oracle step result store the table key. The answer itself and branching on it are unchanged.
- **UI:** a flagged answer shows a "Random event" badge and a button that rolls the declared table through the existing table-roll path, which appends a journal entry.

## Consequences

- No procedure runs: the player chooses to roll the event table and reads the result. Flows are unchanged.
- Presets opt in per oracle. Existing releases and presets behave as before.
- Reusable procedures stay deferred. If they land, the rule can start one instead of offering a table roll, as a new additive field.
- The rule is only valid on a d100 oracle with chaos. Other dice would need their own trigger, which no game asks for yet.
