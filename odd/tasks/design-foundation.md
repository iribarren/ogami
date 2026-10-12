# Feature: design-foundation

- **Locator:** `odd/tasks/design-foundation.md` · Engram topic `odd/design-foundation/tasks`
- **Issue:** #83 · **Branch:** `feat/design-foundation-2-play-layout` (slice 2) · **Status:** slice 1 merged (#84); slice 2 in progress
- **Delivery strategy:** sequential slices to `main` ([ADR 0015](../../docs/adr/0015-sequential-slice-delivery.md)) · merge commit · at most one open PR
- **RDD:** on (global); assess each work-unit commit against the last reviewed boundary
- **Previous feature:** `play-campaign-facts` (PRs #76–#82)

## Objective

Visual design foundation before the M2 playtest presets (roadmap feature 8c, milestone M2; [ADR 0017](../../docs/adr/0017-narrativeflow-model.md) §13). Every Play region now exists: journal, step card, focus mode, dice, oracles, trackers, facts, NPC and Thread panels. This feature adds design tokens (color, type, spacing, light and dark), the Play layout, shadcn component styling, Storybook as the catalogue, and curated per-GameSystem themes.

## Problem and why

The SPA still uses stock shadcn neutral grey tokens. `.dark` is defined but never applied. Geist is the only font, and there is no type or layout scale. The campaign screen sits in the shell's `max-w-5xl`, so the journal is narrow and the 22rem sidebar does not scroll on its own. Storybook has no theme or dark decorators. ADR 0017 lets a release name one curated theme so that a noir game feels different from a fantasy one, and the M2 playtest presets need the theme set settled before they pick one.

## Scope

- **In:**
  - Design tokens in `frontend/src/index.css`: the default app theme (light and dark), semantic colors, type scale, layout tokens.
  - App color mode: Light, Dark or System, chosen in the header and remembered per browser.
  - Restyled shadcn components, plus the few components the Play layout needs.
  - Storybook catalogue: a mode/theme toolbar, a tokens page, and a story for every shared UI component.
  - Automated contrast checks of every palette.
  - The Play campaign layout.
  - Optional release `theme` (schema version 2, additive) carried through Studio validation, the Play ACL and the campaign response.
  - Five curated themes applied inside a campaign whose pinned release names one.
- **Out:**
  - Studio screens beyond the shared tokens.
  - Per-user theme choice, and themes defined by game managers.
  - Theming dice or illustrations.
  - Changing the presets' themes, which the M2 playtest presets feature picks.

## Decisions (2026-10-12, user)

| Question | Decision |
|---|---|
| Theme set | `parchment`, `neon`, `noir`, `eldritch`, `starship`. A release without `theme` uses the default app theme |
| Theme and mode | Each theme has one fixed palette and mode: `parchment` and `starship` are light; `neon`, `noir` and `eldritch` are dark. Inside a themed campaign the theme overrides the user's mode |
| App mode | Follows `prefers-color-scheme` by default, with a Light / Dark / System toggle in the header remembered in `localStorage` |
| Contract | Optional top-level `theme` added **additively to schema version 2**, as `play-threads-npcs` and `play-campaign-facts` did. No v3 |

## Constraints

- Tailwind v4 CSS-first (`@theme inline` in `index.css`) and shadcn `radix-nova`. Tokens stay CSS variables that shadcn components read. Never hard-code colors in components.
- Contrast: every palette (default light, default dark, five themes) passes WCAG AA. That means 4.5:1 for text pairs (`foreground`/`background`, each `*-foreground` on its surface, `muted-foreground` on `background` and `muted`, `destructive` on `background`) and 3:1 for `ring` and `border`-as-control on `background`. A Vitest test checks it from the CSS source, so a palette edit that breaks contrast fails `make test`.
- Frontend libraries are added only when needed ([ADR 0004](../../docs/adr/0004-react-vite-spa-ui-toolkit.md)): `culori` (dev, contrast maths), and at most one `@fontsource` heading font per theme (slice 3). Body text stays Geist in every theme for readability.
- `localStorage` reads and writes are wrapped in `try/catch`. The app renders correctly without storage.
- Schema version 2 is extended **additively**: every release valid today stays valid and reads the same. Never edit `v1.schema.json`. The JSON Schema and the domain validator stay in agreement (`ReleaseSchemaAgreementTest`). Studio validation lives in `Studio\Domain\Release\Version2`, the translator in `Play\Infrastructure\GameSystem`, and the snapshot in framework-free `Play\Domain` ([ADR 0012](../../docs/adr/0012-phpat-boundary-enforcement.md)).
- Glossary terms in code, UI and docs: Theme, GameSystem release, Campaign, Journal, Focus mode, Step card.
- Every slice is safe on `main` alone. The backend `theme` is unused until its UI lands in the same slice.

## Design

### Default app theme

Ogami's own identity is ink on paper with a vermilion seal accent.

| Mode | Background / foreground | Primary |
|---|---|---|
| Light | Warm off-white paper, near-black warm ink | Vermilion (hanko red), white text |
| Dark | Deep blue-black ink, warm off-white text | Lighter vermilion, dark text |

Secondary, muted and accent are warm neutrals, and borders are low-contrast warm grey. Semantic extras are `success`, `warning` and `info` (each with `-foreground`), for oracle answers and tracker states. The chart colors stay.

### Tokens

- **Color:** the shadcn set plus `success`, `warning` and `info`, in `:root` (light) and `.dark`, mapped in `@theme inline`.
- **Type:** `--font-sans` is Geist and `--font-heading` defaults to Geist (themes override it). There is a heading scale: page title, section title, panel title. The journal body has a comfortable reading measure (`max-w-prose` or about 70ch) and line height.
- **Layout:** `--header-height`, `--play-sidebar-width` and the content widths used by the Play layout. Spacing uses Tailwind's scale, so there are no custom spacing tokens beyond layout.
- **Radius:** keep `--radius`, tuned to the identity.

### Color mode

- A `ColorModeProvider` and `useColorMode()` in `frontend/src/shared/theme/` manage `light | dark | system`, stored under `ogami.colorMode`, with a live `prefers-color-scheme` listener. They set `.dark` and `color-scheme` on `<html>`.
- An inline script in `index.html` applies the stored or system mode before first paint, so there is no flash.
- A header toggle offers Light, Dark and System.
- A campaign theme (slice 3) registers a **mode override** in the provider. While the override is set, the effective mode is the theme's, so `dark:` utilities and the tokens agree.

### Play layout

- The campaign route uses a wider container (`max-w-7xl`) than the shell's default `max-w-5xl`. Other pages keep theirs.
- At `lg` and up there are two columns. The main column holds the Journal or Focus mode, at a readable measure. The sidebar (`--play-sidebar-width`, about 24rem) is sticky near the viewport top (the app header is not sticky and scrolls away) and scrolls on its own (`max-height` about `100dvh` minus its top offset).
- The wider container comes from route `staticData` read by `AppShell` (header and main share the width), so only the campaign route opts in.
- Sidebar sections (Dice, Trackers, Facts, Threads, NPCs, Oracles) use one shared `PlayPanel`: a title, an optional count, and a collapsible body. Open or closed state is remembered per section in `localStorage` (`try/catch`). All sections are open by default.
- Below `lg`, the sidebar stacks after the main column, with its sections collapsible.
- In Focus mode the step card is centred at a comfortable width. The Journal/Focus toggle uses a shared segmented control.
- The header and session controls are compact and stay readable on a phone (no horizontal scroll at 360px).

### Curated themes

Each theme is a full palette under `:root[data-theme="<name>"]`. It sets `color-scheme`, every color token and `--font-heading`.

| Theme | Mode | Mood | Palette direction | Heading font |
|---|---|---|---|---|
| `parchment` | light | Fantasy, medieval | Aged paper, sepia-brown ink, oxblood primary, faded gold accent | A classic serif (e.g. EB Garamond / Cormorant) |
| `neon` | dark | Cyberpunk | Near-black violet, hot magenta primary, cyan accent | A geometric display face (e.g. Orbitron / Audiowide) |
| `noir` | dark | Crime, mystery | Greyscale, high contrast, one desaturated amber or blood-red accent | A typewriter face (e.g. Special Elite / Courier Prime) |
| `eldritch` | dark | Horror | Green-black, bone-white text, sickly green / deep teal primary | An old-style or blackletter-lite face (e.g. IM Fell English / Cinzel) |
| `starship` | light | Space sci-fi | Cool white and blue-grey, teal or electric blue primary | A clean technical sans (e.g. Exo 2 / Rajdhani) |

- `data-theme` is set on `<html>` while a themed campaign is open, and removed on leaving it, so Radix portals (popovers, dialogs) are themed too. The app header is themed too, because the whole viewport belongs to that campaign.
- An unknown theme name in a response renders the default theme. Studio and the translator have already refused it.

### Contract: schema version 2 addition

| Part | Shape | Rules |
|---|---|---|
| Release `theme` | optional top-level string | One of `parchment`, `neon`, `noir`, `eldritch`, `starship` (JSON Schema `enum`, mirrored by the Version2 validator). Absent means the default theme. Canonical form omits it when absent |

- Play's translator reads it into `GameSystemSnapshot::theme()` (`?string`). An unknown value fails closed (`InvalidReleaseContent`-style error), like other enums.
- `GetCampaignHandler` puts it into `PinnedReleaseView`, and `PinnedReleaseResponse` gains `theme: ?string`. Run `make api`.

## Slice plan

Forecasts include tests, docs and generated files (OpenAPI spec, TS types). Hard ceiling ~1,700 per slice.

| # | Branch | PR title | Tasks | Forecast |
|---|---|---|---|---|
| 1 | `feat/design-foundation-1-tokens` | `feat(frontend): design-foundation 1/3 tokens` | T1, T2, T3 | ~1,600 |
| 2 | `feat/design-foundation-2-play-layout` | `feat(play): design-foundation 2/3 play-layout` | T4 | ~1,300 |
| 3 | `feat/design-foundation-3-themes` | `feat(play): design-foundation 3/3 themes` | T5, T6, T7 | ~1,300 |

What keeps each slice safe on `main`:
- **Slice 1** restyles every screen with the new tokens and adds the mode toggle. Nothing depends on it.
- **Slice 2** only rearranges the campaign screen.
- **Slice 3** adds the optional `theme`. No preset names one, so releases read the same until a release opts in.

## Tasks

| ID | Slice | Task | Route | Status | Commit |
|---|---|---|---|---|---|
| T1 | 1 | Feature doc. Tokens: the default app theme (light and dark), semantic `success` / `warning` / `info`, type scale, layout tokens in `index.css`. Contrast test (`culori`, reads the CSS source, AA pairs per palette) | delegated writer (slice 1, 4+ non-trivial files) | [x] | `7a909fa` |
| T2 | 1 | Color mode: `ColorModeProvider`, `useColorMode`, mode override hook, no-flash script in `index.html`, header toggle; Vitest tests (stored mode, system mode and its change, override wins, storage failure) | delegated writer (slice 1, 4+ non-trivial files) | [x] | `8476de0` |
| T3 | 1 | Shared UI styling: restyle `shared/ui` components on the tokens, add the components the layout needs (e.g. `badge`, `separator`, `collapsible`, segmented control); Storybook: mode/theme toolbar decorator, `Foundations/Tokens` page (swatches, type scale), a story per shared UI component | delegated writer (slice 1, 4+ non-trivial files) | [x] | `7b553b4` |
| T4 | 2 | Play layout: wider campaign container, sticky self-scrolling sidebar, `PlayPanel` (collapsible, remembered), section restyle, Focus mode width, Journal reading measure, segmented Journal/Focus toggle, phone width; Vitest and story updates; e2e selectors still pass | delegated writer (slice 2) | [ ] | |
| T5 | 3 | Contract and ACL: `theme` in `v2.schema.json` and Version2 validator, fixtures (valid with each theme, invalid unknown / non-string), agreement test, contract doc; translator and `GameSystemSnapshot::theme()`; `PinnedReleaseView` / `PinnedReleaseResponse.theme`; unit and API tests; `make api` | delegated writer (slice 3) | [ ] | |
| T6 | 3 | Themes: five palettes and heading fonts, contrast test covers them, `useCampaignTheme` sets `data-theme` and the mode override on the campaign route and clears them on leave; Storybook theme toolbar lists them; Vitest tests | delegated writer (slice 3) | [ ] | |
| T7 | 3 | Wrap-up: glossary (Theme set), ADR 0017 §13 note that the set is settled, roadmap 8c; e2e smoke (mode toggle persists over reload) | delegated writer (slice 3) | [ ] | |

## Progress

- 2026-10-12: feature started. Theme set, theme mode, app mode and contract shape decided with the user. Doc and slice plan written; issue #83.
- 2026-10-12: slice 1 — T1 done: default light/dark palettes, success/warning/info, type scale, layout tokens; contrast test RED on stock grey tokens (11 failures: missing semantic tokens, muted-foreground, ring), GREEN after the palettes (31 tests). Lowest ratios: light ring 3.91, dark ring 5.48.
- 2026-10-12: slice 1 — T2 done: `ColorModeProvider`, `useColorMode`, `useColorModeOverride`, no-flash script, header select toggle ("Color mode"); provider tests RED (modules missing), GREEN with 11 provider tests + 2 AppShell toggle tests.
- 2026-10-12: slice 1 — T3 done: restyled `shared/ui` on the tokens, added `badge`, `separator`, `collapsible` (shadcn CLI) and `SegmentedControl`, Storybook mode/theme toolbar, `Foundations/Tokens` and a story per component; styling and stories are structural checks only (no RED), `SegmentedControl` has 2 tests, `--input` darkened and added to the 3:1 contrast pairs.
- 2026-10-12: slice 2 — slice 1 review fixes: contrast test resolves `index.css` from `import.meta.dirname` (Vite rewrites `new URL(path, import.meta.url)` into an asset URL) and asserts the exact palette set, quote-tolerant `data-theme` selectors and that no `.dark`/`data-theme` selector goes unparsed; new `noFlashScript.test.ts` runs the `index.html` script in jsdom against every stored mode and system preference. Guard tests: checked by breaking the script's storage key (2 failures), no production code changed.

## Reviews

| Slice | Lineage | Commits | Outcome | PR |
|---|---|---|---|---|
| 1 | `review-9e06a5883ce259ba` | `7a909fa`..`7b553b4` | Approved. Non-blocking, fixed in slice 2: contrast parser could skip a palette silently (assert the exact set); `index.css` resolved from `process.cwd()` instead of `import.meta.url`; no-flash script duplicates the storage key/values untested | [#84](https://github.com/iribarren/ogami/pull/84) |

## Next step

Slice 2 (`feat/design-foundation-2-play-layout`): slice 1 review fixes, then T4, by one delegated writer; then native review and the PR.
