# 0054. The tabletop is a third presentation of the FlowRun

- **Status:** Accepted
- **Date:** 2026-11-28

## Context

[ADR 0016](0016-flow-presentation-journal-with-focus-mode.md) presents a FlowRun as a journal with an optional focus mode. Playing still feels like filling a form, while the manual and the printed cards already give the flow a physical look ([ADR 0051](0051-flow-diagram-layout-and-rendering.md), [ADR 0053](0053-card-image-storage.md)). Feature `play-tabletop` adds a virtual table: a mat of scene cards, a deck, dice, a notebook, an oracle book and a small flow map.

The table needs two things Play does not have. It has no deck, draw or discard state: the server only offers cards (`pick`) and rolls the phase's table. And it has no read path to the release content or to the card images; both are served only to the owning game manager from Studio, and ADR 0053 left Play's own path open.

## Decision

- **A third presentation, same FlowRun.** The route `/play/campaigns/$campaignId/table` reads the same campaign query as the Play page and plays through the same commands. The server stays the only owner of the FlowRun; the table adds no command and no endpoint that changes anything.
- **The table state is derived, never stored.** The mat comes from `campaign.sessions[].scenes` and `campaign.flowRun`: this session's scenes in a row in play order, earlier sessions as piles. There are no saved positions and no free placement.
- **The deck is a picture of the phase's selection rule.** `player`: the offered cards fan out and one is picked. `sequence`: the next card sits on top. `oracle`: drawing is the server's table roll and the rolled card turns face up. A forced scene offers only its card. No shuffle state exists.
- **Two Play read paths, no writes.** `GET /api/campaigns/{campaignId}/release` gives the pinned release field for field like Studio's content response; `GET /api/campaigns/{campaignId}/card-images/{sha256}` gives the bytes of an image that release pins. Only the campaign's owner reads them (404 otherwise, 404 for a hash the release does not pin; 500 for a pinned image with no bytes). Play reaches Studio only through its Published Language behind Play ports. This closes the open line of ADR 0053.
- **The player may read the whole release.** The browser loads the whole flow and every oracle table. Solo play, and the paper cards print everything anyway.
- **No new library.** Card faces reuse the print components in a `.table-cards` scope sized in rem; layout uses container queries.

## Alternatives

| Option | Why not |
|---|---|
| Store the table (positions, deck order) | A second source of truth beside the FlowRun that can drift from it; no feature needs it |
| A real shuffled deck on the server | New domain state and commands for what the selection rule already decides |
| Reuse the Studio read endpoints | They are owner-only and would let Play depend on a Studio draft's key, not on the release the campaign pins ([ADR 0010](0010-versioned-gamesystem-releases.md)) |

## Consequences

- The Play page stays as it is, apart from a link to the table; both views stay in sync because the FlowRun lives on the server.
- The release payload is cached for the pinned version and dropped when the campaign upgrades its release.
- Drawing a card from a deck does not remove it from the deck: the table pictures a rule, not a physical pile.
