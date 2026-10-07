---
# This repository-level document styles the plugin admin UI; it is not a per-site design direction.
name: Stonewright
description: Precise, calm WordPress operations that look native to wp-admin.
colors:
  workspace: "#f0f0f1"
  surface: "#ffffff"
  surface-raised: "#f6f7f7"
  surface-sunken: "#f0f0f1"
  border: "#dcdcde"
  border-strong: "#c3c4c7"
  border-control: "#80848a"
  ink: "#1d2327"
  ink-secondary: "#3c434a"
  ink-muted: "#646970"
  accent: "#4f46e5"
  accent-strong: "#4338ca"
  accent-soft: "#eeefff"
  on-accent: "#ffffff"
  success: "#157347"
  success-soft: "#e7f6ee"
  warning: "#8a5a00"
  warning-soft: "#fff3d6"
  danger: "#b42318"
  danger-soft: "#fdebe9"
  info: "#0369a1"
  info-soft: "#e0f2fe"
  neutral: "#3c434a"
  neutral-soft: "#f0f0f1"
typography:
  headline:
    fontFamily: "inherit (the WordPress admin system stack)"
    fontSize: "24px"
    fontWeight: 600
    lineHeight: 1.25
    letterSpacing: "-0.01em"
  title:
    fontFamily: "inherit"
    fontSize: "16px"
    fontWeight: 600
    lineHeight: 1.25
  body:
    fontFamily: "inherit"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.55
  ui:
    fontFamily: "inherit"
    fontSize: "13px"
    fontWeight: 400
    lineHeight: 1.4
  label:
    fontFamily: "inherit"
    fontSize: "12px"
    fontWeight: 600
    lineHeight: 1.25
  data:
    fontFamily: "ui-monospace, SF Mono, Cascadia Code, Consolas, monospace"
    fontSize: "12px"
    fontWeight: 400
    lineHeight: 1.6
rounded:
  control: "2px"
  sm: "4px"
  md: "8px"
  lg: "12px"
  pill: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  xxl: "32px"
  section: "48px"
  page: "64px"
  gutter: "20px"
controls:
  default: "40px"
  compact: "32px"
  minimum: "24px"
  touch-default: "44px"
  touch-compact: "40px"
motion:
  duration-fast: "100ms"
  duration-base: "150ms"
  duration-slow: "240ms"
  duration-enter: "180ms"
  duration-exit: "120ms"
  duration-toast: "200ms"
  easing-standard: "cubic-bezier(0.2, 0, 0, 1)"
  easing-exit: "cubic-bezier(0.4, 0, 1, 1)"
  scale: "1 (0 when the user asks for reduced motion)"
components:
  button-primary:
    backgroundColor: "{colors.accent}"
    textColor: "{colors.on-accent}"
    typography: "{typography.ui}"
    rounded: "{rounded.control}"
    padding: "0 16px"
    height: "40px"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.ui}"
    rounded: "{rounded.control}"
    padding: "0 16px"
    height: "40px"
  field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.ui}"
    rounded: "{rounded.control}"
    padding: "0 12px"
    height: "40px"
  badge:
    backgroundColor: "{colors.neutral-soft}"
    textColor: "{colors.neutral}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "0 8px"
    height: "22px"
  card:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.ui}"
    rounded: "{rounded.md}"
    padding: "16px"
---

# Design System: Stonewright

This document describes the admin UI as it is built. The shared layer lives in `plugin/assets/admin/sw-ui.css` and `sw-ui.js`, with PHP helpers in `plugin/includes/Admin/Ui/`. Pages that have not moved to the layer still use the older stylesheets (`shell.css`, `admin.css`, page files, `stonewright-admin.css`); section 10 says how the two coexist.

## 1. Overview

**Creative North Star: "The Operator's Workbench"**

Stonewright should feel like a clean, well-made instrument used in daylight on a large monitor: technical, composed, and immediately trustworthy. It sits inside wp-admin and borrows its conventions instead of competing with them. Dense information stays readable through compact type, strong alignment, and selective emphasis.

**Native first.** The product extends WordPress's admin conventions and the colour scheme the user picked. Controls keep core's square corners and native elements (`button`, `input`, `select`, `details`, `dialog`), the accent is the scheme's own, and the page background and neutral ramp are WordPress's.

Quality comes from exact spacing, predictable controls, honest status, and polished edge cases. The system rejects generic AI SaaS card walls, giant metrics, decorative effects, and inconsistent page-specific styling.

**Key Characteristics:**

- One accent, taken from the WordPress colour scheme, with Stonewright indigo as the fallback.
- Compact system typography built for technical scanning; nothing under 12px.
- Flat-by-default surfaces separated by borders and small tonal shifts.
- Responsive structure, never horizontal overflow; tables stack below 783px.
- Clear operational states with text, shape, and colour together.
- Motion that confirms an action in 100 to 240ms and disappears for users who ask for reduced motion.

**Three layers, kept apart.**

1. Tokens: custom properties named `--sw-*`, defined on `.sw-ui`.
2. Components: classes named `sw-ui-*`, defined in `sw-ui.css` only, with PHP helpers that print their markup.
3. Page files: layout only (grid, spacing between components). They contain no colours, radii, or font sizes that are not tokens and no component look-alikes.

## 2. Colors

WordPress supplies the accent and the neutral ramp; Stonewright adds status colours and a few steps between.

### Accent

`--sw-accent` is `var(--wp-admin-theme-color)`, so it follows the user's admin colour scheme, with `#4f46e5` as the fallback when no scheme is present. Its two darker steps (`--wp-admin-theme-color-darker-10` and `-darker-20`) become `--sw-accent-strong` and `--sw-accent-stronger`. Components never use the raw accent directly; they use one of these roles:

| Token | Role | Default |
| --- | --- | --- |
| `--sw-accent-fill` | Solid fills (primary button, switch, selected segment) and every border, underline or outline that shows a state | the accent |
| `--sw-accent-fill-hover` | Hovered fill | the darker-10 step |
| `--sw-accent-text` | Text and icons in the accent colour: links, current tab, focus ring | the darker-10 step |
| `--sw-accent-soft` | Tint behind selected chips and badges | 9% accent over white |
| `--sw-on-accent` | Text on the fill | `#ffffff` |

The darker-10 step reads at 4.8:1 or better on white, on the page and on the accent tint in every scheme WordPress 7.1 ships. A brighter accent for light, midnight, ocean or sunrise would not, so `sw-ui.css` carries a fallback for those four schemes that mixes the step toward the ink until it clears 4.5:1 (`color-mix()`, guarded by `@supports`). `SwUiAccentContrastTest` evaluates those formulas for both palettes, and `ui-contract.spec.ts` measures them in a browser.

**Contrast, measured in the browser with the WordPress 7.1 palette** (ratios rounded down):

| Scheme | Accent text on white | On the page | On the accent tint | White label on the fill | On the hovered fill |
| --- | --- | --- | --- | --- | --- |
| fresh (default) | 5.8 | 5.0 | 5.1 | 4.5 | 5.8 |
| light | 5.8 | 5.0 | 5.1 | 5.5 | 6.2 |
| modern | 6.8 | 6.0 | 6.0 | 5.6 | 6.8 |
| blue | 5.4 | 4.8 | 4.9 | 4.5 | 5.4 |
| coffee | 6.0 | 5.2 | 5.3 | 4.9 | 6.0 |
| ectoplasm | 7.0 | 6.2 | 6.2 | 5.5 | 7.0 |
| midnight | 6.9 | 6.1 | 6.1 | 6.0 | 6.9 |
| ocean | 6.0 | 5.3 | 5.4 | 5.5 | 6.2 |
| sunrise | 8.8 | 7.7 | 7.9 | 7.1 | 7.9 |

### Neutral

- **Workbench** `--sw-bg` (`#f0f0f1`): the page.
- **Paper** `--sw-surface` (`#ffffff`): cards, controls, tables.
- **Raised Paper** `--sw-surface-raised` (`#f6f7f7`): hovered rows, card footers, code chips.
- **Sunken Paper** `--sw-surface-sunken` (`#f0f0f1`): counts, skeletons.
- **Graphite** `--sw-text` (`#1d2327`): primary text.
- **Slate** `--sw-text-secondary` (`#3c434a`): supporting text.
- **Steel** `--sw-text-muted` (`#646970`): metadata and labels; 5.5:1 on white, 4.9:1 on the page, 4.8:1 or better on every status tint.
- **Hairline** `--sw-border` (`#dcdcde`) and **Hairline strong** `--sw-border-strong` (`#c3c4c7`): dividers and card edges.
- **Control edge** `--sw-border-control` (`#80848a`): the border of inputs, buttons, switch tracks and checkboxes; 3.8:1 on white, 3.3:1 on the page.

### Status

Each status has a text colour, a soft fill and a border: `--sw-ok`, `--sw-warn`, `--sw-danger`, `--sw-info`, `--sw-neutral` (with `-soft` and `-border`), plus `--sw-danger-strong` for a hovered destructive fill. The mapping used for new work:

| Meaning | Where it shows | Badge variant |
| --- | --- | --- |
| Active, enabled, healthy, success | lifecycle, audit outcome, checks | `ok` |
| Draft, pending, needs a look, blocked by policy | lifecycle, audit outcome | `warn` |
| Crashed, failed, error | lifecycle, audit outcome, checks | `danger` |
| Built-in, informational | origin, notes | `info` |
| Disabled, off, idle | lifecycle | `neutral` |
| Current step, new | setup, releases | `accent` |
| Kind of thing: read, write, source, type | abilities, skills, files | `tag` (outline, never a state) |

### Named Rules

**The One Accent Rule.** The WordPress accent means action, focus, or current state. Never use it as decoration. Indigo is the fallback and the logo colour, not a second action colour.

**The Complete Status Rule.** Success, warning, danger, and info always pair colour with explicit text or an icon.

**The Muted Text Rule.** Muted text stays on `--sw-surface`, the page, or a status tint, where it is at least 4.5:1. Put nothing lighter than `--sw-text-muted` behind or in front of text.

## 3. Typography

**Font:** the WordPress admin system stack (`font-family: inherit`).
**Mono:** native UI monospace (`--sw-font-mono`).

**Character:** Neutral and exact. Interface labels stay familiar; code, versions, hashes, counts, and times use monospaced or tabular figures (`sw-ui-num`).

### Hierarchy

The scale is `--sw-fs-xs` 12, `-sm` 13, `-md` 14, `-lg` 16, `-xl` 20, `-2xl` 24 (px). Sizes are `--sw-fs-*` so they cannot be mistaken for the `--sw-text-*` colours.

- **Headline** (600, 24px, 1.25; 20px at 782px and below): the page title, one per page.
- **Title** (600, 16px): card, dialog, and empty-state titles. Every page has one card-title size.
- **Body** (400, 14px, 1.55): prose and descriptions, capped near 70 characters.
- **UI** (400, 13px, 1.4): controls, table cells, everything else. It is the base of `.sw-ui`.
- **Label** (600, 12px): badges, table headers, metadata.
- **Data** (400, 12px, mono): identifiers and technical values (`code`).

### Named Rules

**The Compact Evidence Rule.** Metrics never exceed 20px. Technical values wrap (`overflow-wrap: anywhere`) or truncate with a reachable full-value affordance.

**The Twelve Pixel Floor.** No visible text is set under 12px. The contract spec measures it.

## 4. Elevation

Surfaces are flat by default. Borders and tonal shifts establish structure; shadows only separate sticky chrome, menus, toasts, dialogs, or an active hover surface.

### Shadow Vocabulary

- `--sw-shadow-1` (`0 1px 2px rgb(29 35 39 / .06)`): optional for primary panels only.
- `--sw-shadow-2` (`0 6px 18px rgb(29 35 39 / .12)`): popovers and sticky filters.
- `--sw-shadow-3` (`0 16px 40px rgb(29 35 39 / .2)`): dialogs, drawers, toasts.

### Stacking

`--sw-z-sticky` 90 (below the admin bar), `--sw-z-popover` 100000, `--sw-z-modal` 100050 (above Thickbox), `--sw-z-toast` 100060.

### Named Rules

**The Flat-by-Default Rule.** No shadow on every card. Elevation must explain layer or interaction.

## 5. Components

Every component class starts with `sw-ui-`. Components apply inside `.sw-ui` (`Ui\Scope::wrap()`); `Ui\Scope::wrap( $html, [ 'page' => true ] )` also applies the page width (`--sw-content-max`, 1280px). The PHP helper for a component prints its markup, escapes its text, and is covered by an exact-markup unit test; `plugin/tests/fixtures/admin-ui/component-sheet.html` renders all of them on one page and is what the browser specs measure.

Icons come from one inline sprite printed once in the page footer (`Ui\Icon`): 24px stroke drawings that use `currentColor`, shown at 16px (20px with `lg`). An icon never carries a state alone.

### Buttons

`Ui\Button::render( $label, [ variant, size, href, type, icon, icon_only, context, disabled, busy, new_tab, ... ] )`.

- **Variants:** `secondary` (default), `primary`, `tertiary`, `danger` (outline), `danger-solid`. One primary per region. A destructive action is never primary; `danger-solid` is the confirming button inside a confirmation dialog.
- **Sizes:** default 40px, `sm` 32px, `xs` 24px (the WCAG 2.5.8 floor). At 782px and below the default is 44px, `sm` 40px, and `xs` stays 24px.
- **Shape:** 2px radius, like core. Icon-only buttons are square at every size and named by `aria-label`.
- **States:** hover changes colour in 100ms; `:active` presses 1px; `disabled` is greyed and not focusable (a link is marked `aria-disabled`, loses its `href` and leaves the tab order); `busy` sets `aria-busy="true"` and shows a spinner in front of the label (a static dotted ring under reduced motion).
- **Naming:** verb plus object, "Disconnect Example client", not "Disconnect". `context` supplies the object for assistive technology only, so a table of repeated actions keeps the short visible label and every action still has its own name.

### Links

`.sw-ui a` uses `--sw-accent-text`. A standalone link is `sw-ui-link` (24px target; `--external` adds an arrow). Links inside a sentence keep the default style and are exempt from the target size.

### Copy field and code block

`Ui\CopyField::render( $value, [ secret, id, label, copy_label ] )` prints the value in a `code` element (or, for a secret, a read-only password field with a Show value / Hide value toggle), a copy button named "Copy <label>", and a `role="status"` line that says "Copied" for 1.6 seconds, or "Press Ctrl+C" when the clipboard is blocked. Without script the value is still selectable text. A multi-line command is a `sw-ui-code` block: a head with a title and a copy button, and a focusable `pre` with an accessible name.

### Badges, tags, counts and status

`Ui\Badge`. A **badge** states a state (`ok`, `warn`, `danger`, `info`, `accent`, neutral), 22px tall, pill-shaped, with an optional icon or dot; the word carries the meaning. A **tag** states a fact about the thing in a 1px outline with a 4px radius and never a state. A **count** is a number beside a heading or tab (tabular figures). A **status** is a dot and a word for a table cell.

### Notices, callouts and toasts

- **Notice** (`Ui\Notice::render`): reports something that just happened or is true now. `role="status"` for `ok` and `info`, `role="alert"` for `warn` and `danger`. It carries an icon and a title or text, never disappears by itself, and the shell never moves it.
- **Callout** (`Ui\Notice::callout`): guidance that belongs to its place (a warning above a risky form). Static, no live role.
- **Toast** (`Stonewright.ui.toast( message, { action, duration } )`): ephemeral confirmation only, at most three at once, 5 seconds (8 with an action), paused while pointed at or focused, announced through a polite live region. Errors are never toasts.
- **Inserted notice** (`Stonewright.ui.notify( container, { variant, title, text } )`): a notice made by script, announced and eased in.

### Tables

`Ui\Table::render( $columns, $rows, [ 'caption' => ... ] )` prints a real `table` with a (visually hidden) caption, `th scope="col"`, one primary cell per row, secondary columns that hide at 1024px and below, and a right-aligned actions column with a visually hidden header. At 782px and below the table stacks: each row becomes a card, the header row is visually hidden, and every `td[data-label]` shows its column label. `sw-ui-table-wrap` is the contained, focusable scroll region for wide technical data. No other horizontal scrolling is allowed.

### Navigation and tabs

- **Hub navigation** (`sw-ui-hubnav`): links to sibling pages, the current one marked `aria-current="page"`; it scrolls inside its own box when it cannot fit and never wraps to a second row.
- **ARIA tabs** (`sw-ui-tabs` with `data-sw-ui-tabs`): views inside one page. Roving `tabindex`, Arrow, Home and End keys, automatic activation, one visible panel.
- **In-page navigation** (`sw-ui-toc`): anchors inside the page, the current one marked `aria-current="location"`.
- **Filter chips** (`sw-ui-chip-filter`): `aria-pressed` buttons.
- **Page header** (`Ui\PageHeader::render`): one row at least 56px tall with the title, an optional one-line lede (70 characters wide at most), and a right-hand place for badges and the primary action. The shell prints it; a page passes `title`, `lede` and `actions` to `AdminShell::open`.
- **Sticky budget:** sticky and fixed chrome together (admin bar included) covers at most 25% of the viewport height, and nothing sticks below 783px except what the admin bar does. `sw-ui-toolbar--sticky` sticks under the admin bar from 783px up only.

### Page frame, navigation and notices

Every Stonewright page is printed by `AdminShell::open( $slug, [ title, lede, actions, hub ] )` and closed by `AdminShell::close()`. The frame has four parts, in this order:

1. **Skip link** (`a.screen-reader-shortcut` to `#sw-main`): the first stop inside the shell; it shows on focus and moves focus to the content region, so a keyboard user skips the header and the tab bar.
2. **Page header** (`Ui\PageHeader`): the product eyebrow, the one `h1`, a one-line lede, and on the right the page's status and its primary action (`actions`). A page that is still changing carries a **Beta** badge with a visible one-line explanation; the sidebar entry says "Beta" in words too. There is no `EXP` marker and no hover-only explanation.
3. **Hub tab bar** (`Ui\HubNav`): links to the pages of the hub the page belongs to, drawn from the menu registry. A hub with one page has none. Counts beside a tab (open incidents, queued or failed block changes, changes needing a rollback) are numbers with words for assistive technology. A user sees only the tabs they can open.
4. **Content region** (`div#sw-main`, `tabindex="-1"`): the page itself. `hr.wp-header-end` sits between the header and the content, which is where WordPress puts the notices it prints.

The WordPress sidebar is the only global navigation: there is no second navigation bar. It is ordered by hub (`MenuOrder`, one pass at the end of `admin_menu`): Overview, Setup, AI Abilities, Knowledge, Custom code, Activity. The first page of a hub carries the hub's name, the others their own; the top-level entry opens the Overview. Pages register their hub, tab and order once, in `MenuRegistry`; the sidebar, the tab bars, the headers and the Help tabs all read it.

**Notice policy.**

- A notice the plugin prints in its page content stays where the page printed it, and is never moved, folded or removed on a timer.
- Notices WordPress and other plugins print stay where WordPress places them, drawn as core draws them.
- When more than three of those arrive they fold into one disclosure under the header, titled with what it holds ("Other WordPress notices: 1 error, 3 notices"). It starts open whenever it holds an error or a warning.
- An error never disappears by itself.

**Help.** Every page has two native Help tabs: "What is this page?" (the lede, where the page sits and its neighbours) and "Glossary".

The pages that have not moved to the layer keep their own content inside this frame and look as they did; a heading block that such a page prints for itself is hidden so the page keeps one `h1`. The same sticky budget holds: the header is static and the Abilities filter bar sticks under the admin bar from 783px up.

### Forms

- **Field** (`sw-ui-field`): label, control, help text, error text, in that order. `sw-ui-field--sm` and `--md` cap the width.
- **Controls** (`sw-ui-input`, `sw-ui-select`, `sw-ui-textarea`): native elements, 40px (44px at 782px and below), 2px radius, `--sw-border-control` edge. They set only the border colour, the focus ring and the metrics.
- **Error:** `aria-invalid="true"` draws a danger border and an inner ring of the same colour, and the field carries error text with an icon that says the cause and the next step. Colour is never the only cue.
- **Settings rows** (`sw-ui-form-table`): the native `form-table` pattern (label cell, control cell), stacked at 782px and below.
- **Code field** (`sw-ui-textarea sw-ui-textarea--code`): a textarea in monospace that keeps its lines and scrolls a long line inside itself.
- **Inline disclosure** (`sw-ui-disclosure sw-ui-disclosure--inline`): a disclosure inside a table cell, with no card look and a link-like summary that is still a 24px target.
- **Nonce** (`UiNonce::field( $action, $name )`): the hidden nonce input without the id that `wp_nonce_field()` adds, so a page with many forms has no duplicate ids.
- **Switch** (`sw-ui-switch`): a native checkbox with `role="switch"` laid invisibly over a drawn 40 by 24 track, so it keeps form semantics, keyboard and the no-script fallback. The thumb position is the second cue. Name each switch after what it controls. At 782px and below the switch and a checkbox are 40px targets.
- **Checkbox and radio:** a label around them supplies a 24px target; the control keeps core's 16px. A group of two or three exclusive options is `sw-ui-segmented`.
- **Inline help:** `sw-ui-help` is a button that opens a native `popover`, so it works by click, tap and keyboard, not only hover.
- **Why disabled:** `sw-ui-hint`, linked with `aria-describedby`.

### Empty states and skeletons

`Ui\EmptyState::render( $title, $text, [ variant, icon, actions_html ] )` teaches: what this is, why it is empty, and what to do next, with at most one primary action and one link. Variants: `default`, `first-run`, `inline`, `error`, `no-results`. A **skeleton** (`sw-ui-skeleton`, with `--title`, `--text`, `--pill`, `--card`, `--row`) reserves the loaded height so nothing shifts when content arrives; its container is `aria-busy` and carries a visually hidden "Loading" status.

### Disclosure

`details.sw-ui-disclosure` with a `summary` and a `sw-ui-disclosure__body`. A chevron rotates 90 degrees. `data-sw-ui-remember="key"` remembers open or closed per browser; storage is optional and a blocked store changes nothing.

### Dialog and drawer

A modal is a native `<dialog class="sw-ui-dialog">` opened with `showModal()`: `data-sw-ui-dialog-open="#id"` opens it, `data-sw-ui-dialog-close` closes it, `autofocus` marks the safe action that takes focus, Tab and Shift+Tab wrap inside it, Escape closes it, and focus returns to the opener. A confirmation that needs the user to type a phrase sets `data-sw-ui-confirm-phrase` on the input and `data-sw-ui-confirm-submit` on the action; the action stays disabled until the phrase matches exactly, and the phrase is cleared when the dialog closes. A **drawer** (`sw-ui-drawer`) is the same dialog docked to the inline end, 34rem wide and full width at 782px and below; `data-sw-ui-light-dismiss` lets a click outside close it.

### Lineage

`ol.sw-ui-lineage` is an ordered list of operations with nested `ol` for children, a vertical rule per level and a `sw-ui-lineage__node` row per step (badge, name, time, link). It is how a change set, its steps and their outcomes are shown.

### Cards, stats and facts

- **Card** (`Ui\Card::render( $title, $body_html, [ desc, actions_html, footer_html, flush, compact ] )`): a `section` named by its heading, 1px hairline, 8px radius, flat. Status shows as a badge in the header, never as a side stripe. `sw-ui-card--interactive` borders in the accent on hover.
- **Stats band** (`sw-ui-stats` with `sw-ui-stat`): one grouped band of label, value (20px) and meta, with dividers; it replaces a wall of identical cards.
- **Facts** (`Ui\KvList::render`): a description list for audit details, consent facts and receipts.
- **Toolbar** (`sw-ui-toolbar`): search, filters and a result count in one bordered row.

### Scripting

`window.Stonewright.ui` offers `motionOK`, `scrollTo`, `announce`, `toast`, `copy`, `flash`, `notify`, `openDialog`, `closeDialog`, `initTabs`, `initDisclosures`. The script reacts only to `data-sw-ui-*` hooks (`copy`, `reveal`, `tabs`, `remember`, `dialog-open`, `dialog-close`, `confirm-phrase`, `confirm-submit`, `search`, `light-dismiss`), so a page that has not adopted the layer is never touched. `/` focuses the field marked `data-sw-ui-search` unless the user is typing. It builds markup only with `textContent`.

## 6. Motion

Motion confirms what the user just did and shows where something came from. It never decorates and never blocks.

**Rules**

- Only `opacity`, `transform`, colours and `background-position` change over time. Never animate width, height, margin, padding, top or left.
- Interaction feedback lasts 100 to 240ms; every duration is a `--sw-dur*` token. Ambient loops (the spinner at 700ms, the skeleton shimmer at 1400ms) and the saved-row flash (1200ms) are not interaction feedback.
- Leaving is shorter than arriving.
- No `transition: all`.
- A state is never told by motion alone.

**Tokens:** `--sw-dur-fast` 100ms, `--sw-dur` 150ms, `--sw-dur-slow` 240ms (the ceiling), `--sw-dur-reveal` 120ms, `--sw-dur-enter` 180ms, `--sw-dur-exit` 120ms, `--sw-dur-toast` 200ms, `--sw-ease` `cubic-bezier(.2, 0, 0, 1)`, `--sw-ease-in` `cubic-bezier(.4, 0, 1, 1)`. Each duration is a base value times `--sw-motion-scale`.

**Micro-interactions**

| Where | What moves | Time |
| --- | --- | --- |
| Buttons, chips, tabs, hub links, hovered rows and cards | colour and border colour; a button presses 1px on `:active` | 100ms |
| Switch | track colour and thumb position | 150ms |
| Disclosure | chevron rotates; body fades in | 150ms, 120ms |
| Copy | the copy icon is replaced by a check that pops from 90%, and returns after 1.6s | 120ms |
| Notice inserted by script | fades in and settles 4px | 180ms |
| Toast | arrives with a fade and an 8px rise; leaves with a fade | 200ms, 120ms |
| Dialog, drawer | fade and an 8px rise (drawer: a 16px slide) in; faster out; where the browser cannot animate `display` they just appear | 180ms, 120ms |
| Saved row (`Stonewright.ui.flash`) | accent tint fades to nothing; no layout change | 1200ms |
| Busy button, skeleton | spinner and shimmer loops | 700ms, 1400ms |

**Reduced motion.** `@media (prefers-reduced-motion: reduce)` sets `--sw-motion-scale` to `0`, so every duration computes to 0. Loops get a static equivalent (a dotted ring, a flat tint) instead of a zero-length animation, nothing keeps a transform it only held while moving, and `Stonewright.ui.motionOK()` returns false so scripted scrolling is not smooth. The contract spec emulates the preference and asserts that no animation runs and that no element has a non-zero duration or delay.

## 7. Content Design

- Sentence case for every label, heading and button.
- A button names the verb and the object: "Save changes", "Copy MCP server URL", "Disconnect Example client".
- Plurals are real: "1 problem", "2 problems", never "problem(s)".
- Times show in site time, with UTC in the `title`; use a `time` element with `datetime`.
- An error says the cause and the next step ("The MCP endpoint answered 403. Ask the host to allow POST requests to the MCP route, then run the checks again."), never only "Something went wrong".
- An empty state says what the thing is, why it is empty, and what to do next.
- Secrets and keys never appear in examples; synthetic data only.

## 8. Do's and Don'ts

### Do:

- **Do** keep the default workspace light using Workbench and Paper.
- **Do** use the 4px grid (`--sw-space-*`) with larger section breaks.
- **Do** keep every control keyboard reachable with a visible focus ring of at least 2px.
- **Do** test 320px, 390px, 782px, 1024px, and 1440px widths.
- **Do** use semantic HTML and native controls.
- **Do** expose loading, success, error, disabled, empty, and long-content states.
- **Do** name every control, and name repeated controls by what they act on.
- **Do** use a component from the layer or ask for it; do not write a look-alike in a page file.

### Don't:

- **Don't** build generic AI SaaS dashboards from identical oversized metric cards.
- **Don't** use giant type that reduces useful information density.
- **Don't** use dark-first, neon, gradient, glassmorphism, or ornamental interfaces.
- **Don't** ship low-contrast pills, hidden overflow, cramped controls, or unexplained status colour.
- **Don't** use coloured side stripes on cards, callouts, notices, or errors.
- **Don't** use display fonts in labels, controls, tables, or data.
- **Don't** relocate plugin content out of its context. The shell moves only notices that belong to other plugins and WordPress core; a plugin notice stays where the page prints it.
- **Don't** use `!important` in the shared layer, and don't put component CSS in page files.
- **Don't** use inline `style` attributes.
- **Don't** make essential information hover-only.
- **Don't** auto-dismiss an error or a warning; only a confirmation toast leaves by itself.
- **Don't** make a destructive action the primary button.
- **Don't** use `transition: all`, remove focus without replacement, or animate layout properties.
- **Don't** ship project-specific names, URLs, memory, audit data, or credentials as product defaults.

## 9. Admin Surface Audit

This is the release checklist for every Stonewright-owned wp-admin surface that is registered today, grouped by the hub it belongs to. Design Studio, Visual Workspace and Blueprints are not registered pages and are not audited. Every page starts with the frame of section 5 (skip link, one page header, the hub's tab bar, notices under the header).

| Hub | Surface | Primary job | Visual contract | Risk to re-check | Own states to verify |
| --- | --- | --- | --- | --- | --- |
| Overview | Overview | Answer is it working, what needs me, what happened | A status band (connection, mode, tool surface, last activity, bridge), a table of items that need attention with a state word and one action each, recent activity, and the next setup step as the only primary action | The bridge tile shows a state, never the stored URL; a source that cannot be read reads as nothing to report, never as an error | nothing needs attention; setup unfinished; no activity yet; abilities off or blocked; long values |
| Setup | Setup | Connect and update safely | Numbered steps, grouped choices, readable code, explicit verification receipts | Release checks bypass stale caches; secrets never enter examples | signed-out client; failed check; copied; long command |
| Setup | Troubleshoot (beta) | Diagnose a failed AI client connection | Diagnostic cards, status badges, in-place Run diagnostics with a loading spinner | The script path does not reload the page; the no-script form remains | running; all pass; a blocked route; long report |
| AI Abilities | AI Abilities | Search and gate tools | One toolbar that sticks under the admin bar from 783px up (search, what is shown and what is on, bulk actions), providers with collapsed categories, a table per category with a named switch per row; parameters load when a row opens | The switch and the bulk action call REST routes that keep the form handlers' capability, nonces and writer (`AbilityToggles`); the bulk form is the way in without script; an error never goes away by itself | no match; Apply with nothing chosen; server refuses or cannot be reached; master switch off; 400+ rows; parameters fail to load |
| Knowledge | Skills | Manage reusable instructions | Catalog and editor split, clear provenance and lifecycle | Fresh installs include only product defaults; user data survives updates | empty catalog; invalid skill; long body |
| Knowledge | Memory | Manage durable site knowledge | Compact table, explicit lifecycle and status, edit and delete actions, a labelled instructions field | Fresh installs start empty; updates preserve user data | no entries; at the character limit |
| Knowledge | Context (beta) | Persist operator context for every MCP agent | Two-column system and user layout, collapsible generated snapshot | Compact task-start carries truncated user context text | empty; saved; long text |
| Knowledge | Design (beta) | Persist the active design direction | Shared tokens and a compact direction list and editor | Compact task-start points at the design-direction brief; it does not inline the full contract | no direction; import rejected |
| Knowledge | Prompt library | Find a safe task starter | Search-first catalog, grouped outcomes, copy action | Long prompt and tool text wraps inside its surface | no match; copied; long prompt |
| Custom code | Drafts, Library, Active, Crash recovery | Review code before activation | The hub's tab bar carries the four views; a status badge per file; "New file" is the one primary action; Delete asks first in a dialog whose confirm button is not primary; the Library's kind, category and status are one toolbar, not a second row of tabs | The name rule, file storage (`.draft` and `.bak`), nonces and production-safe tokens are unchanged; status text and payloads stay legible at narrow widths | no files; no library files; filter matches nothing; crashed file; long path; file too large to edit |
| Custom code | Approvals | Issue a scoped grant | The human-approval warning is a callout on every view; the exact candidate as facts with a risk badge and a bounded diff; one primary action; the token in a copy field, never masked, and a binding receipt | The token is never obscured, logged, or persisted by accident; the page stops at human approval | no proposal selected; unknown proposal; expired; failed; long binding |
| Activity | Audit log | Diagnose and understand outcomes | Filter panel, responsive rows, a full-width readable payload; a count of open incidents on the tab | Payload, cause, and repair copy never escape or collapse the table | empty log; filtered to nothing; long payload |
| Activity | Block queue console (beta) | Watch queued block changes | Inside the shell, keep-open guidance, a status line that is announced, a journal; a count of queued and failed changes on the tab | Opening it never saves content by itself; editors see only the tabs they can open | nothing queued; a failed change; opened without a session token |
| Activity | Rescue | Roll back a change or check the site loads | The safe-mode action in the page header; a table of changes that need attention with one rollback each; a count of changes needing attention on the tab | Every action needs a nonce and, in production-safe mode, a confirmation token for exactly one change | nothing to rescue; unconfirmed change; production-safe |
| Hidden | Authorization pages | Approve or deny an application | The application, how it is identified, and the scheme, host and port it returns to, as facts; Approve and Deny | Every client-supplied value is escaped; the destination keeps its port | expired request; unnamed application |
| Hidden | Connected OAuth clients | Review and disconnect clients | Redirects to the connected clients list | Every disconnect action names its client | no clients |

### Cross-page release checks

The measurable gates run in `e2e/` (see `e2e/README.md`):

- **Page loop** (`admin-ui.spec.ts`): every page loads with the shared shell, no console error and no horizontal overflow at 1440, 1024, 782, 390 and 320px, and axe (WCAG 2.0, 2.1 and 2.2 A and AA) reports no serious or critical finding at 1440 and 390px beyond the page's allowance.
- **UI contract** (`ui-contract.spec.ts`): no visible text under 12px; no target under 24px except links inside a sentence; no plugin-owned notice in the "other WordPress notices" drawer; the first `h1` starts within 120px at 1440 and 200px at 390; every primary button is painted with the accent fill; no duplicate id and no unnamed control or field; sticky chrome covers at most 25% of the viewport.
- **Colour schemes:** the accent contrast table in section 2, for all nine schemes and for the brighter earlier palette.
- **Reduced motion and forced colours:** no animation runs under reduced motion; under forced colours badges keep their borders and icons, a selection is drawn in the system highlight pair, the focus ring uses the system highlight and the switch keeps its border.
- **Reflow:** 320px with no two-dimensional scroll except a `sw-ui-table-wrap`.
- **Shell** (`ui-contract.spec.ts`, every viewport): one visible `h1` that names the page, no header band or second navigation, one tab bar per hub with the current tab marked, the skip link first and landing in the content region, the sidebar in hub order, notices folding at more than three and opening for an error, the Beta marker in words, the plugin row and Help tab entry points.

Page allowances live in `e2e/tests/helpers/ui-budget.ts`. They only go down: when a page moves to the layer, its entry is deleted.

Also check on every release:

- Keep body copy at 12px or larger.
- Use one token system; page styles may extend layout, never redefine the palette.
- Treat plugin version, release version, configured bridge version, and live companion version as different facts.

## 10. Migration

The layer is opt-in. Nothing outside an element with the class `sw-ui` is touched, so a page that has not moved looks exactly as it did; a browser test compares every computed style of legacy markup with the layer's stylesheet on and off.

**Tokens.** Inside `.sw-ui` every older token name resolves to a token of the layer, so a legacy rule that still reaches markup inside an island takes the island's values. A test fails when a name declared in `shell.css` has no alias.

| Older names | Resolve to |
| --- | --- |
| `--sw-brand`, `--sw-brand-strong`, `--sw-brand-fill`, `--sw-brand-fill-hover`, `--sw-brand-soft`, `--sw-on-brand` | `--sw-accent-text`, `--sw-accent-stronger`, `--sw-accent-fill`, `--sw-accent-fill-hover`, `--sw-accent-soft`, `--sw-on-accent` |
| `--sw-text-xs` to `--sw-text-2xl` | `--sw-fs-xs` to `--sw-fs-2xl` |
| `--sw-ok-text`, `--sw-warn-text`, `--sw-danger-text`, `--sw-info-text`, `--sw-success`, `--sw-warning` and their `-bg` forms | the status tokens |
| `--sw-color-*`, `--sw-ink*`, `--sw-gray-*`, `--sw-surface-subtle` | the neutral tokens |
| `--sw-active-*`, `--sw-disabled-*`, `--sw-crashed-*`, `--sw-draft-*`, `--sw-builtin-*`, `--sw-user-*`, `--sw-uploaded-*` | the status tokens (see the mapping in section 2) |
| `--sw-shadow`, `--sw-shadow-hover`, `--sw-transition` | `--sw-shadow-1`, `--sw-shadow-2`, `--sw-dur` with `--sw-ease` |

The shell header band is gone, but its tokens (`--sw-shell-header-*`) keep their values until the unregistered Design Studio stylesheet that still names one goes. Aliases are kept for at least one release after the last page that uses an old name has moved, and their removal is listed in the changelog.

**Classes.** The component classes are new names (`sw-ui-*`), not renames, so an older stylesheet and the layer can never collide. A page moves component by component: replace the older markup with the helper, and delete the older rule when nothing uses it.

**Older stylesheets that still apply to a migrated page.** `shell.css` styles form controls and buttons inside `.sw-shell` with `!important`, so inside a legacy shell a control keeps the older colours and the red invalid border of a field cannot show; the error text and its icon still do. This ends when the shell's generic form rules go.

**Adding or migrating a page**

1. Print the page inside the shell with `AdminShell::open( $slug, [ title, lede, actions ] )` (drop the page's own heading block), register it once with `MenuRegistry::add( $slug, $label, $hub, [ tab, order, beta ] )`, and wrap its content: `Ui\Scope::wrap( $html, [ 'page' => true ] )`.
2. Build it from the helpers; where one is missing, ask for it rather than writing a look-alike.
3. Keep the page CSS to layout, with tokens only (no colours, radii or font sizes of its own), and no inline `style`.
4. Register page assets after the shell's (`[ 'stonewright-admin-shell' ]`), so the layer, which the shell depends on, loads first.
5. Show every state in the audit table's last column.
6. Run the page contract, delete the page's entry from `ui-budget.ts`, and extend `STONEWRIGHT_PAGES` if the page is new.
