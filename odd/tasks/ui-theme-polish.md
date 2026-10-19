# Feature: ui-theme-polish

- **Locator:** `odd/tasks/ui-theme-polish.md` · Engram topic `odd/ui-theme-polish/tasks`
- **Issue:** #227 · **Branch:** `feat/ui-theme-polish-1-depth` · **Status:** in progress (slice 1)
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-guided-polish` (PR #226)

## Objective

Give the UI depth and a game feel before the next preset playtests (roadmap backlog B5), following the design canvas https://claude.ai/artifact/6MWYwPaXvQVUJYb7cXGiSo (private; boards `Main.dc.html` with a `theme` prop for the six themes, and `Inputs.dc.html`). Frontend only. The values below are distilled from `Main.dc.html` (it wins over `Inputs.dc.html`); writers work from this doc, not the canvas.

Out of scope: new themes; layout changes. The design also restructures layouts, and these stay as they are: the campaign header breadcrumb, kicker and chips; the app bar; the six-die grid and an in-panel "Last roll" box; the Oracles single select and in-panel answer box; journal entries as horizontal cards; new composer buttons; step pips and "Step 2 of 4" in the step card header; moving the PlayPanel chevron.

## Problem and why

Inputs share their card's background, cards are flat, and every theme differs only in colors and the heading font. Neon feels like a game; the other themes do not. The playtests should judge the finished look.

## Current code (explorer map, 2026-10-19)

- Themes: `:root` (default "ogami") and `.dark` in `frontend/src/index.css`, plus `:root[data-theme='parchment'|'neon'|'noir'|'eldritch'|'starship']` (`shared/theme/themes.ts`, set by `useCampaignTheme`). Themes override raw shadcn variables; `@theme inline` maps colors; type tokens (`--font-heading`, `--text-*-title`) live in a non-inline `@theme` block, so themes can override them.
- `shared/theme/contrast.test.ts` parses only plain palette blocks and counts every `.dark` / `[data-theme` in `index.css`: **per-theme styling must be CSS variables declared inside the existing palette blocks**, read by theme-neutral classes. Every token in `:root` must be declared by every theme unless listed in `NOT_PALETTE_TOKENS`.
- Inputs: `shared/ui/input.tsx`, `textarea.tsx`, `native-select.tsx` (`bg-transparent … dark:bg-background`, `border-input`). The design's well border equals `--input` in every theme, so wells keep `border-input`.
- Cards: `shared/ui/card.tsx` (`shadow-xs ring-1`), `play/campaigns/PlayPanel.tsx:64` (`rounded-xl border bg-card`), `play/flow/StepCard.tsx:43`, `play/journal/JournalEntryView.tsx:65-87`.
- Read-outs: `RollView` (`JournalEntryView.tsx:235-254`), `LikelihoodView` (`:287-305`), challenge dice in `CheckView` (`:165-191`). The Dice and Oracles panels show no result in the panel; trackers have no pips (`TrackersPanel.tsx` `TrackerRow`, bounded by `min`/`max`).
- Icons: `lucide-react` is a dependency, used only by `checkbox.tsx`; inline SVG chevrons in `PlayPanel.tsx` and `flow/Disclosure.tsx`. Dice presets `d20, 2d6, 4d6kh3, d%` (`play/dice/DiceRoller.tsx:7`, `aria-label` "Roll d20").
- Storybook: `.storybook/preview.ts` already has `theme` and `colorMode` globals and a decorator (`shared/theme/StoryTheme.tsx`); no story pins a theme yet (`globals: { theme: 'neon' }` per story).
- Fonts missing for the design: Geist Mono (neon and starship labels), EB Garamond 400 (parchment prose), IM Fell English italic (eldritch labels).
- Tests a markup change can break: `TrackersPanel.test.tsx:58` (name is the first `span` of each `li`; pips must not be `li`s or precede the name), e2e `play-layout.spec.ts` and `PlayPanel.test.tsx` (trigger names, so icons are `aria-hidden`), `DiceRoller.test.tsx` and e2e (`Roll d20` names), journal article names (`entryLabel`).

## Decisions (2026-10-19, parent)

| Question | Decision |
|---|---|
| Theme mechanism | Tokens inside the seven palette blocks (`:root`, `.dark`, five themes), consumed by theme-neutral utilities or classes in `index.css`. No per-component theme branches, no theme-scoped selectors. Non-color tokens (shadows, textures, case, sizes) are declared in every block |
| Default `.dark` | No design values; derive from the noir/neon pattern: well a step darker than `--background`, `inset 0 2px 5px oklch(0 0 0 / 0.5)`, card shadow `inset 0 1px 0 oklch(1 0 0 / 0.04), 0 12px 26px -12px oklch(0 0 0 / 0.7)`, ogami dot texture in `oklch(1 0 0 / 0.04)`, ogami type and shapes |
| Colors | Keep the existing palette colors where the design differs slightly (`secondary`, `chip`, noir `card`); new color tokens (label, icon, tile foreground) get contrast pairs in `contrast.test.ts` (text 4.5:1, icons 3:1) |
| Read-out surfaces | The journal's `RollView` total, `LikelihoodView` answer and `CheckView` challenge dice sit in wells; no new in-panel boxes |
| Tracker pips | New pip bar under the tracker name for trackers with `max − min ≤ 12`, one pip per point above `min`, filled = `primary`, empty = well. Decorative (`aria-hidden`); the edit controls stay |
| Dice shapes | The four presets keep their labels and get a die outline: `d20` hexagon, `2d6` and `4d6kh3` square, `d%` kite. A shared `DieShape` covers d4–d20 for later use |
| Title mark | A decorative mark before the campaign page `h1` (no other header change). Noir has no mark (the design's "Unsolved" stamp is copy and placement, out of scope). Parchment's seal shows the campaign initial |
| Divider ornament | One ornament divider between the campaign header and the panel grid |
| Panel frame | Per-theme border, `--radius` per theme; neon corner and starship brackets only on the step card and the Dice and Oracles panels (a `frame` opt-in) |
| Icon-only buttons | Existing text-glyph buttons (−, +, ×, chevrons) get lucide icons with `aria-label`; no new buttons |
| Stories | One story per theme for each touched component, through a small shared helper; the default story keeps the toolbar theme |

## Design spec

### Depth (slice 1)

| theme | `--well` | `--well-shadow` | `--card-shadow` | texture (`background-image` / size) |
|---|---|---|---|---|
| ogami | `oklch(0.95 0.012 80)` | `inset 0 1px 2px oklch(0.22 0.015 60 / 0.12)` | `inset 0 1px 0 oklch(1 0 0), 0 1px 2px oklch(0.22 0.015 60 / 0.06), 0 8px 18px -8px oklch(0.22 0.015 60 / 0.14)` | `radial-gradient(oklch(0.22 0.015 60 / 0.05) 1px, transparent 1.3px)` / `6px 6px` |
| parchment | `oklch(0.975 0.018 90)` | `inset 0 1px 3px oklch(0.4 0.06 60 / 0.2)` | `inset 0 1px 0 oklch(1 0 0 / 0.5), 0 10px 22px -12px oklch(0.35 0.06 55 / 0.4)` | `radial-gradient(ellipse at 50% 35%, transparent 50%, oklch(0.62 0.08 65 / 0.3) 100%)` / `100% 100%` |
| neon | `oklch(0.11 0.025 295)` | `inset 0 2px 8px oklch(0 0 0 / 0.55), inset 0 -1px 0 oklch(0.82 0.13 205 / 0.35)` | `inset 0 1px 0 oklch(1 0 0 / 0.05), 0 0 0 1px oklch(0 0 0 / 0.35), 0 0 28px -10px oklch(0.65 0.27 340 / 0.45)` | `repeating-linear-gradient(0deg, oklch(1 0 0 / 0.03) 0px, oklch(1 0 0 / 0.03) 1px, transparent 1px, transparent 3px)` / `auto` |
| noir | `oklch(0.11 0 0)` | `inset 0 2px 5px oklch(0 0 0 / 0.65)` | `inset 0 1px 0 oklch(1 0 0 / 0.04), 0 12px 26px -12px oklch(0 0 0 / 0.85)` | `radial-gradient(ellipse at 50% 25%, transparent 40%, oklch(0 0 0 / 0.6) 100%)` / `100% 100%` |
| eldritch | `oklch(0.12 0.025 170)` | `inset 0 2px 10px oklch(0 0 0 / 0.6), inset 0 0 14px oklch(0.72 0.17 140 / 0.05)` | `inset 0 0 36px oklch(0.72 0.17 140 / 0.07), inset 0 1px 0 oklch(1 0 0 / 0.04), 0 14px 30px -14px oklch(0 0 0 / 0.85)` | `radial-gradient(ellipse at 50% 0%, oklch(0.72 0.17 140 / 0.08), transparent 55%), radial-gradient(ellipse at 50% 45%, transparent 45%, oklch(0 0 0 / 0.5) 100%)` / `100% 100%` |
| starship | `oklch(0.945 0.014 240)` | `inset 0 1px 3px oklch(0.22 0.04 255 / 0.13)` | `0 1px 2px oklch(0.22 0.04 255 / 0.06), 0 10px 22px -12px oklch(0.22 0.04 255 / 0.2)` | `linear-gradient(oklch(0.5 0.11 195 / 0.07) 1px, transparent 1px), linear-gradient(90deg, oklch(0.5 0.11 195 / 0.07) 1px, transparent 1px)` / `32px 32px` |

Primary button shadow (`--button-shadow`): ogami `inset 0 1px 0 oklch(1 0 0 / 0.18), 0 1px 0 oklch(0.42 0.17 33), 0 4px 10px -3px oklch(0.55 0.19 33 / 0.45)`; parchment `inset 0 1px 0 oklch(1 0 0 / 0.18), 0 2px 0 oklch(0.3 0.12 25)`; neon `0 0 0 1px oklch(0.78 0.2 340), 0 0 18px -2px oklch(0.65 0.27 340 / 0.75)`; noir `3px 3px 0 oklch(0.05 0 0)`; eldritch `0 0 20px -4px oklch(0.72 0.17 140 / 0.65)`; starship `inset 0 1px 0 oklch(1 0 0 / 0.2), 0 4px 10px -4px oklch(0.5 0.11 195 / 0.6)`.

Read-out numbers (roll total, oracle answer) glow (`--readout-shadow`): neon `0 0 6px oklch(0.82 0.13 205 / 0.8), 0 0 18px oklch(0.82 0.13 205 / 0.45)`; eldritch `0 0 16px oklch(0.72 0.17 140 / 0.55)`; others `none`.

### Type (slice 1)

| theme | label: font / size / weight / case / tracking / style / caps | label color | page title: tracking / case / text-shadow | panel title: size / weight / case / tracking | prose font / size | button font / size / case / tracking |
|---|---|---|---|---|---|---|
| ogami | sans / 12px / 500 / none / 0 / normal / normal | `oklch(0.46 0.02 65)` | -0.02em / none / none | 15px / 600 / none / 0 | sans / 15px | sans / 14px / none / 0 |
| parchment | EB Garamond / 15px / 600 / none / 0.05em / normal / all-small-caps | `oklch(0.43 0.05 60)` | 0.005em / none / `0 1px 0 oklch(1 0 0 / 0.55)` | 19px / 700 / none / 0 | EB Garamond / 18px | EB Garamond / 16px / none / 0.02em |
| neon | Geist Mono / 11px / 500 / uppercase / 0.14em / normal / normal | `oklch(0.82 0.13 205)` | 0.05em / uppercase / `0 0 6px oklch(0.65 0.27 340 / 0.9), 0 0 22px oklch(0.65 0.27 340 / 0.55)` | 13px / 600 / uppercase / 0.08em | sans / 15px | Orbitron / 12px / uppercase / 0.08em |
| noir | Special Elite / 12px / 400 / uppercase / 0.1em / normal / normal | `oklch(0.72 0 0)` | 0.02em / none / `1px 1px 0 oklch(0 0 0 / 0.6)` | 15px / 400 / uppercase / 0.06em | Special Elite / 15px | Special Elite / 14px / uppercase / 0.08em |
| eldritch | IM Fell English / 15px / 400 / none / 0.01em / italic / normal | `oklch(0.74 0.04 150)` | 0.01em / none / `0 0 20px oklch(0.72 0.17 140 / 0.4)` | 19px / 400 / none / 0.01em | IM Fell English / 17px | IM Fell English / 16px / none / 0.02em |
| starship | Geist Mono / 11px / 500 / uppercase / 0.12em / normal / normal | `oklch(0.44 0.04 250)` | 0.01em / none / none | 15px / 600 / uppercase / 0.06em | sans / 15px | Exo 2 / 14px / uppercase / 0.06em |

Labels are the `Label` primitive and the ad-hoc uppercase group labels (`OraclePanel.tsx:130-135`, `FocusPanel.tsx:109`). Prose is journal note text and step prompts. Page title sizes stay the existing `--text-page-title` tokens.

### Icons (slice 2)

Icon tile tokens (tile 32px on panel headers, 36px on journal entries, 44px on the step card; icons `aria-hidden`):

| theme | `--tile-radius` | `--tile-bg` | `--tile-border` | `--tile-fg` | `--icon` (free icons) |
|---|---|---|---|---|---|
| ogami | 9px | `oklch(0.55 0.19 33 / 0.09)` | `1px solid oklch(0.55 0.19 33 / 0.22)` | `oklch(0.5 0.19 33)` | `oklch(0.5 0.19 33)` |
| parchment | 50% | `oklch(0.8 0.1 88)` | `1px solid oklch(0.6 0.1 80)` | `oklch(0.3 0.06 60)` | `oklch(0.4 0.14 25)` |
| neon | 2px | `oklch(0.82 0.13 205 / 0.08)` | `1px solid oklch(0.82 0.13 205 / 0.55)` | `oklch(0.82 0.13 205)` | `oklch(0.82 0.13 205)` |
| noir | 0 | `transparent` | `1.5px solid oklch(0.76 0.1 80)` | `oklch(0.76 0.1 80)` | `oklch(0.76 0.1 80)` |
| eldritch | 50% | `oklch(0.35 0.07 185 / 0.55)` | `1px solid oklch(0.72 0.17 140 / 0.4)` | `oklch(0.72 0.17 140)` | `oklch(0.72 0.17 140)` |
| starship | 4px | `oklch(0.92 0.035 215)` | `1px solid oklch(0.5 0.11 195 / 0.35)` | `oklch(0.42 0.1 200)` | `oklch(0.48 0.11 198)` |

lucide-react names: panels Dice → custom d20 (`DieShape` d20 with the inner triangle `M12 7 16.5 15h-9z`), Trackers `HeartPulse`, Facts `ScrollText`, Threads `Route`, NPCs `Users`, Characters `UserRound`, Oracles `Eye`; collapse `ChevronDown` (rotated −90° when closed, replaces the inline SVGs); step card `Target`; journal heading and notes `Feather`, roll and check entries the d20, oracle entries `Eye`; end scene `CircleStop` where a button already exists.

Die outlines (`DieShape`, viewBox 24, `fill: none`, `stroke: var(--icon)`, width 1.7, `stroke-linejoin: round`, 20px): d4 `M12 3 21.5 20h-19z`; d6 `M4 4h16v16H4z`; d8 `M12 2 22 12 12 22 2 12z`; d10 `M12 2 21 10 12 22 3 10z`; d12 `M12 2.5 21.5 9.4 17.9 20.5H6.1L2.5 9.4z`; d20 `M12 2 21 7v10l-9 5-9-5V7z`. Preset buttons show the shape above the label (56px tall), current grid kept.

### Embellishments (slice 3)

Title mark (52–56px, before the campaign `h1`, `aria-hidden`; monochrome marks as `mask-image` SVGs tinted by `--icon`, so a theme sets `--mark-image` / shape tokens):
- ogami: square, radius 10px, bg `--primary`, `rotate(-4deg)`, shadow `inset 0 0 0 3px oklch(0.985 0.005 85 / 0.28), 0 2px 6px -2px oklch(0.55 0.19 33 / 0.5)`; seal glyph (circle r9, a vertical and a horizontal line, stroke 2.4, cream).
- parchment: wax seal, `border-radius: 48% 52% 50% 46% / 50% 46% 54% 50%`, bg `oklch(0.4 0.14 25)`, shadow `inset 0 0 0 3px oklch(0.32 0.12 25), inset 0 0 0 6px oklch(0.48 0.14 25 / 0.7), 0 3px 6px oklch(0.3 0.06 55 / 0.45)`, `rotate(-8deg)`; campaign initial in EB Garamond 30px 700 `oklch(0.86 0.06 45)`.
- neon: hexagon outline `M26 3 46 14.5v23L26 49 6 37.5v-23z` (viewBox 52), cyan stroke 2, `drop-shadow(0 0 6px oklch(0.82 0.13 205 / 0.75))`.
- noir: none.
- eldritch: sigil (circle r25, dashed circle r21 `2 4`, a triangle, an eye and a pupil), stroke `oklch(0.72 0.17 140)` 1.6, `drop-shadow(0 0 8px oklch(0.72 0.17 140 / 0.55))`.
- starship: radar (circle r22, dashed circle r13 `3 3`, four crosshair ticks, a needle to (37,17) with a dot), stroke `oklch(0.5 0.11 195)` 1.6.

Divider ornament (margin `22px 0 26px`): ogami 1px lines `oklch(0.82 0.015 80)` around a 7px square rotated 45° in `oklch(0.55 0.19 33)`, gap 12px; parchment a double rule (1px top and bottom `oklch(0.58 0.06 65)`, 2px apart) around an 84×18 oxblood diamond `M42 3l6 6-6 6-6-6z` with scrolls and end dots; neon a 2px magenta bar glowing `0 0 10px` then 12px and 96px cyan bars glowing `0 0 8px`; noir `border-top: 1px dashed oklch(0.55 0 0)`; eldritch hairlines `oklch(0.85 0.08 150 / 0.3)` around three circles (r2, r5 with a filled r1.5 dot, r2; 60×14); starship a 1px line `oklch(0.5 0.11 195 / 0.5)` over 6px of ticks `repeating-linear-gradient(90deg, oklch(0.5 0.11 195 / 0.45) 0px, oklch(0.5 0.11 195 / 0.45) 1px, transparent 1px, transparent 12px)`.

Panel frame (`--panel-border`; `--radius` per theme, card / control): ogami `1px solid oklch(0.88 0.012 80)` 12/8; parchment `3px double oklch(0.66 0.06 70)` 4/3; neon `1px solid oklch(0.82 0.13 205 / 0.25)` 2/2 plus a magenta corner triangle top right (16px, 12px on the sidebar; `oklch(0.65 0.27 340)`) and on the step card a 64×2px cyan glowing bar at the bottom, left 24px; noir `1px solid oklch(1 0 0 / 0.16)` 0/0; eldritch `1px solid oklch(0.85 0.08 150 / 0.2)` 10/6; starship `1px solid oklch(0.85 0.02 245)` 6/4 plus 2px `oklch(0.5 0.11 195)` corner brackets at −1px (all four, 16px, on the step card; top-left and bottom-right, 12px, on Dice and Oracles).

Drop cap on journal note prose (`::first-letter`): parchment and eldritch only, head font, 58px, `line-height: .82`, `float: left`, `padding: 6px 10px 0 0`; parchment `oklch(0.4 0.14 25)`, eldritch `oklch(0.72 0.17 140)` with `text-shadow: 0 0 14px oklch(0.72 0.17 140 / 0.5)`.

Pips (height / radius): ogami 10px / 999px; parchment 12px / 50%; neon 8px / 1px; noir 10px / 0; eldritch 12px / 50%; starship 8px / 1px. Tracker pips `flex: 1`, gap 5px; empty pip bg `--well`, `box-shadow: inset 0 0 0 1px var(--input), var(--well-shadow)`.

## Constraints

- Frontend only; no API change, no `make api`.
- `contrast.test.ts` stays green: tokens in palette blocks only, contrast pairs for new color tokens.
- Accessible names unchanged; every icon `aria-hidden`; icon-only buttons have an `aria-label`.
- Planning heuristic only: forecasts are not caps; the slice ceiling is ~1,700 changed lines.

## Slice plan

| Slice | Branch | Tasks | Forecast |
|---|---|---|---|
| 1 | `feat/ui-theme-polish-1-depth` | T1–T5 | T1–T3 landed at ~607 changed lines (CSS tokens are compact), so the icon tasks joined this slice: ~1,200–1,500 in all (tile tokens ~100, `IconTile` and panel, step card and journal icons ~300, `DieShape` and dice buttons ~150, stories and tests ~250) |
| 2 | `feat/ui-theme-polish-2-embellishments` | T6–T8 | ~1,100–1,400 (mark and divider ~400, panel frame ~250, drop cap and pips ~250, stories and tests ~350, Chrome pass fixes ~100) |

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | This feature doc | parent doc, writer commits (delegated writer, direct) | [x] | `dc86cf3` |
| T2 | 1 | Depth: `--well`, `--well-shadow`, `--card-shadow`, `--button-shadow`, `--readout-shadow`, texture tokens in all seven palette blocks; wells on Input, Textarea, NativeSelect and the journal read-outs; layered shadows on Card, PlayPanel, the step card and journal entries; body texture; primary button shadow; per-theme story helper and stories | delegated writer, direct | [x] | `5511ddb` |
| T3 | 1 | Type: label, page title, panel title, prose and button tokens; `Label` and the ad-hoc group labels; missing fonts (Geist Mono, EB Garamond 400, IM Fell English italic); contrast pair for the label color; stories | delegated writer, direct | [x] | `542c729` |
| T4 | 1 | Icon tiles: tile and `--icon` tokens, `IconTile`, lucide icons on PlayPanel headers (chevron included), the step card and the journal; lucide icons on existing icon-only buttons; contrast pairs; stories | delegated writer, direct | [x] | `0d58319` |
| T5 | 1 | `DieShape` (d4–d20) and the dice preset buttons drawn as their dice; stories | delegated writer, direct | [x] | `966d680` |
| T6 | 2 | Title mark before the campaign title and the divider ornament under the header; panel frame tokens, `--radius` per theme, neon corner and starship brackets on the step card, Dice and Oracles; stories | | [ ] | |
| T7 | 2 | Journal note drop cap; tracker pip bar with per-theme pip shapes; stories | | [ ] | |
| T8 | 2 | Chrome pass on a campaign in each of the six themes (light and dark default); fix small findings, log the rest | | [ ] | |

## Progress

- 2026-10-19: feature started; design canvas and theme code mapped by a read-only explorer, decisions taken, issue #227, 3 slices.
- 2026-10-19: T1 feature doc committed by the slice 1 writer (no code, no RED/GREEN applicable).
- 2026-10-19: T2 depth tokens in all seven blocks, wells on Input/Textarea/NativeSelect and journal read-outs, card and button shadows, body texture, `onTheme` story helper and per-theme stories (delegated writer, direct; contrast.test token-presence check GREEN only, CSS has no meaningful RED).
- 2026-10-19: T3 per-theme label, page/panel title, prose and button type tokens in all seven blocks, `type-label`/`type-prose`/`type-button`/`panel-title-case` utilities, Geist Mono added, label contrast pairs (no design color changed), note stories per theme (delegated writer, direct; contrast.test token and pair checks GREEN only, tests came after the CSS).
- 2026-10-19: T1–T3 came in at ~607 changed lines, so the plan went from 3 slices to 2: the icon tasks T4–T5 join slice 1, the embellishments T6–T8 become slice 2.
- 2026-10-19: T4 tile tokens in all seven blocks (`.dark` keeps ogami shapes, colors from the dark primary), `IconTile`, `DieShape` (pulled forward for the Dice panel icon), tiles on panels, step card and journal entries, lucide chevrons, `X` on the fact link remove button, contrast pairs for `--tile-fg` and `--icon` (no design color changed; GREEN only, tests came after the code). The link remove `×` in `play/lists/TagFields.tsx` is outside the allowed surface and stays a glyph (delegated writer, direct).
- 2026-10-19: T5 dice preset buttons draw `d20` hexagon, `2d6`/`4d6kh3` square and `d%` kite above their labels (56px, names unchanged; custom presets get no shape); `DieShape` itself landed in the T4 commit; per-theme DiceRoller and DieShape gallery stories (delegated writer, direct; DiceRoller shape tests RED first: 4 failed, then GREEN).
- 2026-10-19: T4 follow-up by the parent: the free-tag remove button in `TagFields.tsx` (outside the writer's surfaces) uses the lucide `X` like `FactsPanel`; `src/play/lists` Vitest 32 passed. Route: parent, inline (one mechanical file).

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|

## Next step

Slice 1: T4–T5 on `feat/ui-theme-polish-1-depth`, then review and PR (1/2).
