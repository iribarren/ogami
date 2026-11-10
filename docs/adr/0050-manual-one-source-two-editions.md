# 0050. One manual, two editions

- **Status:** Accepted
- **Date:** 2026-11-09

## Context

Friends joining the public alpha have no guide to Studio, and nothing explains how to design a narrative flow, which is Studio's main value. The same method also helps a person who plays solo on paper. Feature `studio-manual` ([#387](https://github.com/iribarren/ogami/issues/387), [feature doc](../../odd/tasks/studio-manual.md)) writes that manual with two complete worked examples: *The Long Carry* on the original GameSystem Hard Country and *Cold Berth* on the original GameSystem Dogwatch.

Two readers need different books. One plays on paper and must not meet the app. The other authors in Studio and needs the reference. Writing and keeping two manuals in step would not last.

Both games are original and written for the book, so no third-party rules text is adapted. Cairn and 24XX influenced their design and are credited as influences only.

While writing the first chapters, two Studio rough edges showed up. The rail listed sections in an order unrelated to how a flow is designed, and a designer picking oracle tables in a step had to leave the step to create one.

## Decision

- **One source, two editions.** The chapters are written once. The *Paper* edition leaves out the "In Ogami" blocks and Parts III and IV (authoring a GameSystem and the Studio reference), and never mentions the app. The *Full* edition keeps everything. Both use the [glossary](../domain/glossary.md) terms.
- **Delivery.** Full is read in the app. Paper, and the printable card sheets of both flows, are PDFs built from the same source.
- **Licence.** The whole manual and both presets are CC BY-SA 4.0, by choice. Credits state the licence and name the design influences.
- **Top-down order.** The manual teaches from the Promise to playtest and share. The Studio section rail follows it: Overview, Flows, Scene Types, Trackers & tags, Oracles, Sheet, Checks, Gear, Library; the Ship group is unchanged.
- **New table from a step.** The step editor creates an oracle table without leaving the step: a *New table* control under a prompt step's inspiration tables and under a `table` step's table picker, in the Scene Type and Flow editors of a GameSystem draft. It makes a weighted table with its first entry, selects it in the step and links to its editor. Library packs, `oracle` steps, Scene Type shortcuts and `check` steps keep today's behaviour.
- **Version.** The draft version is draft-wide ([ADR 0028](0028-studio-gamesystem-drafts.md)). The create carries the open editor's version, and the editor adopts the one it answers with, so its unsaved step still saves. A stale version keeps the draft and offers the editor's reload.

## Consequences

- Readers of either edition see the same chapters; a change to the method is made once.
- Anyone who shares the manual must keep CC BY-SA 4.0 and the credits.

- The PDF pipeline and the in-app reader are later slices of the feature; this ADR fixes only the shape.
- A designer can work down the rail and create what a step needs where it is needed. The editors gain a dependency on the oracle create endpoint, supplied only where the version is the editor's own.

## Amendment, 2026-11-09

The first plan adapted Cairn and 24XX. The games are now two original GameSystems, Hard Country and Dogwatch, written for this book, so the manual adapts no third-party rules text. Cairn by Yochai Gal and 24XX by Jason Tocci are credited as design influences only. CC BY-SA 4.0 is kept by choice, though no share-alike source requires it any more.

## Amendment, 2026-11-10

The three PDFs (the Paper edition and the card sheets of both flows) are published as assets of a `manual-1.0` GitHub Release and are linked from the Full edition only, inside "In Ogami" blocks, so the Paper edition names no URL. The appendices are in both editions, except the paper-to-app map, which is Full only.
