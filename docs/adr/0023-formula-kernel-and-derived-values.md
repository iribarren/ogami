# 0023. Formula kernel and derived values

- **Status:** Accepted
- **Date:** 2026-10-13

## Context

Feature `rules-derived-values` (roadmap 11) adds DerivedValues: sheet values computed by a formula over a Character's fields and the Campaign's trackers (for example a modifier, an initiative score or a wound threshold). Checks (feature 12) will add formula modifiers to dice. Until now a band bound or a `tracker` effect value can name a tracker (`{"tracker": "chaos"}`), and [ADR 0017](0017-narrativeflow-model.md) left room for "a reference is a trivial formula".

Four questions needed answers:

1. **Where does formula evaluation live?** Studio validates formulas at publish and Play evaluates them. Both may depend on a shared kernel's Domain, and nothing else ([ADR 0012](0012-phpat-boundary-enforcement.md)). The only existing parser is Randomness's dice notation (`DiceExpression`), which has no identifiers or functions.
2. **Which schema version?** [ADR 0022](0022-character-sheets-in-schema-version-2.md) and the release contract said derived values would open schema version 3.
3. **What does "live on the sheet" mean?** Should derived values update as the player types, or only after a save?
4. **Formula syntax.** Keys allow `a-z`, `0-9` and `-` and may start with a digit, so a bare `str-10` is ambiguous.

## Decision

### A new `Formula` shared kernel

Formula evaluation lives in a new shared kernel, `backend/src/Formula/`, not in Randomness and not in `Shared`.

- The `Domain` is pure and framework-free. It holds a tokenizer, a recursive-descent parser into an AST, an evaluator, and the formula's references. It also orders formulas by their dependencies and rejects cycles.
- Evaluation takes an injected **value resolver**: Play resolves sheet values and tracker values, and the kernel knows nothing about Characters or Campaigns.
- PHPat lists `Formula` in `CONTEXTS` and `SHARED_KERNEL`. Like Randomness, other contexts use its Domain directly.
- Formulas are deterministic and have nothing random about them, so they do not belong in Randomness. A Check (feature 12) composes a Randomness dice roll with Formula modifiers in Play, and neither kernel imports the other.
- `Formula` does not share code with the dice parser. It follows the same arithmetic rules: integer division rounds down, and division by zero and overflow are errors.

### Additive in schema version 2

Derived values and formula bounds and effect values are added to schema version 2 without a new version, as the sheet and `credits` were. Every release that was valid before stays valid and keeps its hash. This amends ADR 0022 and the contract's note that derived values open schema version 3. Sheet-field effects and checks are decided by their own features.

- A sheet Field gains the type `derived`: `{key, label, hint?, group?, type: "derived", formula}`. A Character stores no value for it, and writing one is refused (422).
- A band `upTo` and a `tracker` effect `value` accept `{"formula": "…"}` alongside an integer and `{"tracker": key}`. Play reads `{"tracker": key}` as the trivial formula `{tracker:key}`.

### The formula language

```
formula    := expression                                   (1–200 characters, whitespace ignored)
expression := term (("+" | "-") term)*
term       := unary (("*" | "/") unary)*
unary      := "-" unary | primary
primary    := integer | reference | call | "(" expression ")"
call       := ("min" | "max") "(" expression ("," expression)+ ")"   (2–10 arguments)
reference  := "{" key "}" | "{" key ".max" "}" | "{tracker:" key "}"
```

- `{str}` reads a `number` field, a `derived` value, or the current value of a `track` field. `{hp.max}` reads a `track` field's max. `{tracker:chaos}` reads a Campaign tracker, with the same spelling as the flow placeholder.
- Braces keep keys unambiguous: `{str}-10` is a subtraction.
- Integer literals run from 0 to 1,000,000. Every intermediate result stays within ±1,000,000,000, so PHP and JavaScript compute the same exact integers. `/` is floor division.
- Function names are lowercase. Identifiers outside braces other than `min` and `max` are rejected.

**Checked at publish** (Studio) and again by Play's translator, failing closed:

- The syntax is valid.
- Every reference exists and has the right kind: a `text` or `list` field and `.max` on a non-track field are rejected.
- Derived values have no cycles.
- A flow formula reads trackers only, because there is no Character in a flow's scope.

**Evaluation errors** (division by zero, overflow) are not caught at publish:

- A derived value that fails to evaluate reads as `null`.
- A flow band or effect that fails to evaluate refuses the action with a clear 422.

### Evaluated by PHP and by TypeScript, held equal by shared vectors

- PHP is authoritative. The Character in the Campaign response carries its derived values, computed from the stored values and the current tracker values. Play's flows evaluate band and effect formulas server side.
- A small TypeScript evaluator in `frontend/src/shared/lib/formula/` recomputes the derived values as the player edits a Character, so the sheet updates live. Studio's formula editor (M4) will use the same evaluator.
- One conformance file (`backend/contracts/formula/conformance.json`) lists formulas, inputs and expected results or errors. PHPUnit and Vitest both run it, so the two evaluators cannot drift. The node container mounts `backend/contracts` read-only for this.

## Consequences

- There is a fourth shared-kernel context. It is small and pure, and checks reuse it for modifiers.
- No release changes hash. Studio's validator and the JSON Schema grow, and `ReleaseSchemaAgreementTest` keeps them aligned.
- There are two evaluators to maintain. The conformance vectors are the contract between them, and a new operator or function lands in both, with vectors, in the same change.
- Derived values are never stored, so a release upgrade (feature 20) or a tracker change recomputes them for free.
- Sheet-field effects, checks and anything beyond integer arithmetic with `min` and `max` (rounding modes, conditionals, dice inside formulas) are left until a real game needs them.
