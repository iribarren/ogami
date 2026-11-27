# Feature: manual-flavor-images

- **Locator:** `odd/tasks/manual-flavor-images.md` · Engram topic `odd/manual-flavor-images/tasks`
- **Issue:** [#435](https://github.com/iribarren/ogami/issues/435) · **Branch:** `feat/manual-flavor-images-1-illustrations` · **Status:** slice 1/1 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Roadmap:** B37 in [`docs/roadmap.md`](../../docs/roadmap.md); manual shape from [ADR 0050](../../docs/adr/0050-manual-one-source-two-editions.md)

## Objective

Flavor illustrations in the manual, in the Full (web) and the Paper (PDF) edition:

- **Art style guides** in `docs/art/`: Hard Country (*The Long Carry*), Dogwatch (*Cold Berth*), and a neutral house style for the method and Studio chapters. Each guide ends in a reusable prompt preamble. The format is open to more styles.
- **24 approved placements** (D9), each a one-line `::image{…}` directive with its file name, style, size, alt text and subject.
- **Placeholders:** until the file exists, the manual shows a box of the final size with the full prompt (preamble plus subject), the alt text and the file name, on the web and in the Paper PDF. The user generates each image and drops it into `frontend/src/manual/images/`.
- **Credits:** the AI-generated illustrations are credited in both credits chapters.

Out of scope: generating the images (the user does), the Spanish edition (B30).

## Problem and why

The manual is text and flow diagrams only. Nothing sets each game's mood, and there is no agreed art direction, so images generated ad hoc would drift between chapters and games, and could break the voice packs' taboos.

## Evidence

- **Directives:** `frontend/src/manual/editionBlocks.ts` has one regex `flowDirective` (:27); any other `::name` line throws "unknown directive" (:72-79). The `Segment` union is at :22.
- **Web:** `ManualChapterPage.tsx:25` is a two-way ternary (markdown / `ManualFlowDiagram`); each in-ogami segment becomes its own `<aside>` (:33-40). `Markdown.tsx` is `react-markdown` + `remark-gfm`, no `img` override; raw HTML is inert (`Markdown.test.tsx:27-33`).
- **Paper:** `print/paperEdition.ts` keeps `editions === 'both'` chapters and common segments; `PaperBlock` (:9) and `blockOf` (:18) know markdown and flow-diagram only; `PaperBook.tsx:40` is a two-way ternary.
- **Print CSS:** `@page paper` A4, margins 22/20/24 mm (content ~170 × 251 mm); each chapter `break-before: page` (:84); `.paper figure { break-inside: avoid }` (:104); only `figure svg` is sized (:109). The Paper theme is black on white (:1).
- **PDF build:** `print/manual-pdf.spec.ts:15-30` serves `dist-print/` with a MIME map of html/js/css/woff2/woff only (anything else 404s) and waits only for `document.fonts.ready` (:47).
- **Assets:** `frontend/public/` has only `favicon.svg`; the pattern is a Vite import (`src/shared/credits/badges.ts:3`). The print build uses `base: './'` (`vite.print.config.ts`), so imported assets work in both builds.
- **Content tests:** `content.test.ts:68` requires exactly one in-ogami segment per Part I/II page; the no-app checks read markdown segments only (:30-52); the diagram placement pin (:166-196) is the model for an image pin. `paperEdition.test.ts:59-75` and `PaperBook.test.tsx` are the models for Paper tests.
- **Voice packs:** `docs/voices/README.md` (front matter `key`, `gameSystem`, `status`; fixed `##` headings). Taboos: Hard Country has no heroics, no named church or doctrine, nothing modern, no humour; the saint is never named and never a corpse; the bearers are unnamed. Dogwatch has no creature or alien, no hint at what is aboard, no heroics, no invented science; the custodian is never hostile; no named ship computer, androids or corporation logos.
- **Credits:** `content/credits-and-license.md` and `content/credits-and-inspirations.md` duplicate the "This book" section (CC BY-SA 4.0).

## Decisions

- **D1 Style guides** live in `docs/art/`, mirroring `docs/voices/`: `README.md` (format, how to add a style, generation sizes, the shared rules), then `house.md`, `hard-country.md`, `dogwatch.md`. Front matter `key`, `gameSystem` (`—` for house), `status: draft`. Fixed headings: Sources, Setting, Palette and materials, Motifs, Mood, Composition, Taboos, Prompt preamble. A new GameSystem or flow manual adds one file and one entry in `styles.ts` (D5).
- **D2 Shared rules (every style):** reads in grayscale (the Paper edition prints black on white); no lettering, numbers, symbols or logos anywhere (card faces, pages and screens blank or illegible); no faces (figures from behind, or only hands); no gore. Neutral house style: pen-and-ink line art with a flat, muted wash on warm paper; tabletop objects (cards, dice, an open notebook, a hand writing).
- **D3 Directive:** one line, attributes in this fixed order, values in double quotes with no `"` inside:
  `::image{file="hc-ford.webp" style="hard-country" size="half" alt="…" subject="…"}`
  `file` matches `^[a-z0-9-]+\.(webp|png|jpg)$`; `style` is a key of `styles.ts`; `size` is `banner`, `half` or `spot`; `alt` and `subject` are non-empty. Anything else on an `::image` line throws a clear "malformed directive" error, like `::flow-diagram`. The segment carries its edition like a diagram does.
- **D4 Rendering:** a `<figure>` sized by `size`. With the file present, an `<img>` with the alt text and `loading="lazy"` (eager in Paper). Without it, a placeholder of the same box with a dashed border: "Illustration to come", the file name, the alt text and the full prompt (preamble + space + subject). Same on the web and in the Paper PDF. No caption.
- **D5 Preambles in the frontend:** `frontend/src/manual/images/styles.ts` holds each style's key, name and preamble, copied verbatim from the guide's `## Prompt preamble`. The node container mounts only `frontend/`, `backend/contracts` and `backend/presets` (`compose.yaml:100-105`), so Vitest cannot read `docs/` and no test compares the two; both the README and a comment in `styles.ts` say they change together.
- **D6 Sizes:** banner full width, 3:1; half floats right at ~45% width, 4:3; spot floats right at ~30% width, 1:1. Floats become full width on a phone. Paper: banner 170 × ~57 mm; half 80 mm wide; spot 50 mm; `break-inside: avoid`. Generation sizes (README): banner 2400 × 800, half 1600 × 1200, spot 1200 × 1200, saved as `.webp`.
- **D7 Images** are resolved with `import.meta.glob('./images/*.{webp,png,jpg}', { eager: true, query: '?url', import: 'default' })`, so the app and the print build both find them.
- **D8 Paper PDF:** the spec's MIME map gains webp, png, jpg and svg; it waits for every `<img>` to decode before printing.
- **D9 Placements** (approved by the user, 2026-11-27): all 24 below, all in common text (never inside `:::in-ogami`), pinned by a content test. File prefixes `hc-`, `dw-`, `house-`.
- **D10 Credit:** both "This book" sections gain: *"Illustrations generated with ‹image tool› from prompts by ‹author›, released with the book under CC BY-SA 4.0."* ‹author› is the name the "This book" section already credits (no new personal data). The tool's name stays a placeholder until the user chooses (user decision, 2026-11-27).
- **D11 One slice.** The forecast fits the budget; if it grows past ~1,700 lines, split before review: slice 2 = T3 (placements) and T4 (credits).

### Placements (D9)

| # | Chapter | Style | Size, position | Subject |
|---|---|---|---|---|
| 1 | what-you-need | house | half, after `## Always` | Notebook, pencil, a set of dice, a deck and index cards seen from above |
| 2 | the-two-games | hard-country | half, after the Hard Country heading | Litter poles and ropes on two shoulders seen from behind, a shrouded shape |
| 3 | the-two-games | dogwatch | half, after the Dogwatch heading | One open, empty berth in half-light, bedding folded too neatly |
| 4 | the-promise | hard-country | spot, after `## The Long Carry` | A rag-wrapped foot on frosty ground |
| 5 | the-promise | dogwatch | spot, after `## Cold Berth` | A vent grille in a dim corridor wall |
| 6 | scene-types | hard-country | half, after `## The Long Carry` | Tea-brown river, a frayed rope on a willow, a boot on the far bank |
| 7 | memory | hard-country | spot, after `## The Long Carry` | Dogs curving wide round a litter's shadow on a road |
| 8 | pressure | hard-country | spot, after `## The Long Carry` | Frost-stiff ropes and poles by a dying fire at dusk |
| 9 | session-rituals | hard-country | spot, after `## What the two flows do` | Litter against a byre wall at night, one lantern, a watcher from behind |
| 10 | truths-and-endings | dogwatch | half, after `## Cold Berth` | Three sealed blank slips on a steel table under a handlight |
| 11 | cards-and-print | house | spot, after `## Seeding a finale` | A squared deck, one card slid in near the bottom, backs only |
| 12 | likelihood-oracles | house | spot, chapter opener | Two dice mid-tumble over an open notebook |
| 13 | trackers-and-clocks | dogwatch | spot, after `## Cold Berth` | Gloved hands patching a scrubber by handlight |
| 14 | cards-and-dice | dogwatch | spot, after `## Cold Berth` | Five face-down cards fanned on a metal deck, one set aside |
| 15 | journaling-and-lists | house | half, after `## The journal` | Open notebook, faint pencil lines, one struck through |
| 16 | rules-and-story (Full) | house | spot, chapter opener | One set of dice beside several closed notebooks |
| 17 | studio-tour (Full) | house | spot, chapter opener | A workbench of index cards and cards being laid out, a pen |
| 18 | printing-a-release (Full) | house | half, chapter opener | A printed card sheet, a craft knife cutting along the crop marks |
| 19 | flow-the-long-carry | hard-country | banner, under the opening question | Bearers from behind carrying the litter across a high moor toward a pass under snow clouds |
| 19b | flow-the-long-carry | hard-country | spot, after `## The endings` | An empty shrine gate in the first snow |
| 20 | flow-cold-berth | dogwatch | banner, under the opening question | A long berth deck at half-light, rows of closed berths, one lid open |
| 20b | flow-cold-berth | dogwatch | spot, after `## The endings` | A closed hatch with a frosted porthole |
| 21 | rules-hard-country | hard-country | half, after `## Load` | The kit on a blanket: rope, lantern, staff, waterskin, bandages, knife, sling |
| 22 | rules-dogwatch | dogwatch | half, after `## Kit` | The kit on a deck plate: multitool, cutting torch, patch kit, pry bar, handlight, tether, flare |

## Slice plan

| Slice | Tasks | Forecast (incl. generated) | Branch |
|---|---|---|---|
| 1/1 | T1–T4 | ~1,200–1,500 lines: style guides ~400, directive parser + tests ~150, `styles.ts` + image lookup ~60, web figure/placeholder + CSS + tests ~200, Paper block + print CSS + tests ~150, PDF spec ~20, 24 directives + content pin ~150, credits ~10, feature doc ~150 | `feat/manual-flavor-images-1-illustrations` |

Shippable: the placeholders are meant to be visible on `main` until each image lands (the user's request); the Paper PDF shows them too.

## Tasks

| ID | Slice | Task | Route | Done | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Art style guides `docs/art/` (D1, D2, D6): README, house, hard-country, dogwatch, each with a prompt preamble | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | |
| T2 | 1 | `::image` directive (D3), `styles.ts` (D5), image lookup (D7), web figure and placeholder (D4, D6), Paper block and print CSS (D6), PDF spec MIME and image wait (D8), tests | delegated writer (Sonnet): slice of 2+ non-trivial files | [x] | |
| T3 | 1 | The 24 placements (D9) in the chapters, with a content test pinning slug, file, style and size, all common, styles known, no app words in alt/subject | delegated writer (Sonnet): slice of 2+ non-trivial files | [ ] | |
| T4 | 1 | Credits (D10) in both "This book" sections | delegated writer (Sonnet), with T3 | [ ] | |

## Acceptance criteria

- Every placement shows, in both editions where its chapter appears, a placeholder of its final size with the file name, alt text and full prompt; dropping the file into `frontend/src/manual/images/` replaces it with the image and no code change.
- A malformed `::image` line, an unknown style or size, or a bad file name fails the page loudly, never a half page.
- The Paper PDF builds with placeholders, and with images once they exist (no 404, images decoded before printing).
- No image placement sits inside an `:::in-ogami` block; Part I/II pages still have exactly one block.
- Alt text, subjects and preambles mention no app (Paper never meets the app).
- The guides honour the voice packs' taboos and the shared rules (D2).

## Checks

- Vitest: parser (valid, malformed, unknown style/size, bad file, inside a fence ignored), web figure with and without a file, Paper block, content pin of the placements.
- ESLint, Prettier, tsc via `make verify`.
- `make manual-pdf` once, to see the placeholders in the Paper PDF; a Chrome pass of two chapters (one half, one banner) on desktop and phone width.

## Progress

- 2026-11-27: Feature doc created; one slice, four tasks. Placements (D9) and the credit wording (D10) approved by the user; the tool name stays a placeholder. Issue #435.
- 2026-11-27: T1 done: `docs/art/` README plus house, hard-country and dogwatch guides, each taboo traced to its voice pack line. Docs only, so no RED; checked by reading the guides back against the packs.
- 2026-11-27: T2 done: `::image` parser, `styles.ts`, glob lookup, `ManualImage` (web and Paper), print CSS, PDF spec. RED observed for the parser (25 failing before the code, 58 passing after); the component, Paper and style tests were written together with their code, so no separate RED was observed for them. `make frontend-fix` run; Vitest on `src/manual` green.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

T1–T4 by one Sonnet writer on `feat/manual-flavor-images-1-illustrations`, then native review, PR `feat(manual): manual-flavor-images 1/1 illustrations`.
