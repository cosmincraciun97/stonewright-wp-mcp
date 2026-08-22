---
# This repository-level document styles the plugin admin UI; it is not a per-site design direction.
name: Stonewright
description: Precise, calm WordPress operations in a restrained light workspace.
colors:
  workspace: "#f7f8fb"
  surface: "#fcfcfe"
  surface-raised: "#f1f3f8"
  border: "#dfe2ea"
  border-strong: "#c8ceda"
  ink: "#171921"
  ink-secondary: "#474c57"
  ink-muted: "#68707d"
  brand: "#4f46e5"
  brand-strong: "#4338ca"
  brand-soft: "#eeefff"
  on-brand: "#ffffff"
  success: "#157347"
  success-soft: "#e7f6ee"
  warning: "#8a5a00"
  warning-soft: "#fff3d6"
  danger: "#b42318"
  danger-soft: "#fdebe9"
  info: "#0369a1"
  info-soft: "#e0f2fe"
typography:
  headline:
    fontFamily: "-apple-system, BlinkMacSystemFont, Segoe UI, system-ui, sans-serif"
    fontSize: "24px"
    fontWeight: 650
    lineHeight: 1.2
    letterSpacing: "-0.015em"
  title:
    fontFamily: "-apple-system, BlinkMacSystemFont, Segoe UI, system-ui, sans-serif"
    fontSize: "16px"
    fontWeight: 650
    lineHeight: 1.3
  body:
    fontFamily: "-apple-system, BlinkMacSystemFont, Segoe UI, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: "-apple-system, BlinkMacSystemFont, Segoe UI, system-ui, sans-serif"
    fontSize: "12px"
    fontWeight: 600
    lineHeight: 1.35
  data:
    fontFamily: "ui-monospace, SFMono-Regular, Cascadia Code, Consolas, monospace"
    fontSize: "12px"
    fontWeight: 500
    lineHeight: 1.45
rounded:
  sm: "6px"
  md: "10px"
  lg: "14px"
  pill: "999px"
spacing:
  xs: "4px"
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "24px"
  xxl: "32px"
  section: "48px"
components:
  button-primary:
    backgroundColor: "{colors.brand}"
    textColor: "{colors.on-brand}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "9px 14px"
    height: "36px"
  button-secondary:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.label}"
    rounded: "{rounded.sm}"
    padding: "9px 14px"
    height: "36px"
  field:
    backgroundColor: "{colors.surface}"
    textColor: "{colors.ink}"
    typography: "{typography.body}"
    rounded: "{rounded.sm}"
    padding: "8px 10px"
    height: "36px"
  status-pill:
    backgroundColor: "{colors.surface-raised}"
    textColor: "{colors.ink-secondary}"
    typography: "{typography.label}"
    rounded: "{rounded.pill}"
    padding: "4px 9px"
---

# Design System: Stonewright

## 1. Overview

**Creative North Star: "The Operator's Workbench"**

Stonewright should feel like a clean, well-made instrument used in daylight on a large monitor: technical, composed, and immediately trustworthy. Light surfaces keep WordPress tasks familiar; dense information stays readable through compact type, strong alignment, and selective emphasis.

Quality comes from exact spacing, predictable controls, honest status, and polished edge cases. The system rejects generic AI SaaS card walls, giant metrics, decorative effects, and inconsistent page-specific styling.

**Key Characteristics:**

- Restrained light palette with one indigo action color.
- Compact system typography built for technical scanning.
- Flat-by-default surfaces separated by borders and small tonal shifts.
- Responsive structure, never horizontal overflow.
- Clear operational states with text, shape, and color.

## 2. Colors

Tinted cool neutrals create a quiet workspace; indigo appears only for primary actions, focus, and current location.

### Primary

- **Tool Indigo** (`#4f46e5`): primary actions, current navigation, focus, and direct links.
- **Deep Tool Indigo** (`#4338ca`): hover and active states.
- **Indigo Wash** (`#eeefff`): selected or informational backgrounds.

### Neutral

- **Workbench** (`#f7f8fb`): page background.
- **Paper** (`#fcfcfe`): cards, controls, and tables.
- **Raised Paper** (`#f1f3f8`): hover rows, grouped metrics, and secondary panels.
- **Graphite** (`#171921`): primary text.
- **Slate** (`#474c57`): supporting text.
- **Steel** (`#68707d`): metadata and labels.
- **Hairline** (`#dfe2ea`): default border.

### Named Rules

**The One Tool Color Rule.** Indigo means action, focus, or current state. Never use it as decoration.

**The Complete Status Rule.** Success, warning, danger, and info always pair color with explicit text or an icon.

## 3. Typography

**Display Font:** system UI sans
**Body Font:** system UI sans
**Label/Mono Font:** native UI monospace

**Character:** Neutral and exact. Interface labels stay familiar; code, versions, hashes, counts, and times use stable tabular or monospaced figures.

### Hierarchy

- **Headline** (650, 24px, 1.2): page title only.
- **Title** (650, 16px, 1.3): section and panel titles.
- **Body** (400, 14px, 1.55): instructions and descriptions, capped near 70 characters.
- **Label** (600, 12px, 1.35): controls, metadata, table headers, and badges.
- **Data** (500, 12px, 1.45): identifiers and technical values.

### Named Rules

**The Compact Evidence Rule.** Metrics never exceed 20px. Technical values wrap or truncate with a reachable full-value affordance.

## 4. Elevation

Surfaces are flat by default. Borders and tonal shifts establish structure; shadows only separate sticky chrome, menus, focused panels, or an active hover surface.

### Shadow Vocabulary

- **Resting surface** (`0 1px 2px rgba(23, 25, 33, 0.05)`): optional for primary panels only.
- **Raised control** (`0 6px 18px rgba(23, 25, 33, 0.10)`): dropdowns and sticky filters.
- **Overlay** (`0 16px 40px rgba(23, 25, 33, 0.16)`): dialogs and floating inspectors.

### Named Rules

**The Flat-by-Default Rule.** No shadow on every card. Elevation must explain layer or interaction.

## 5. Components

### Buttons

- **Shape:** compact rounded rectangle, 6px radius, minimum 36px desktop height and 44px touch height on narrow screens.
- **Primary:** Tool Indigo fill, pure white text, 9px by 14px padding.
- **Hover / Focus:** deeper fill on hover; 2px visible Tool Indigo focus ring.
- **Secondary / Ghost:** Paper or transparent background with Hairline border and Graphite text.

### Chips

- **Style:** 22px minimum height, pill radius, semantic soft background, strong readable text, and optional 1px semantic border.
- **State:** text remains visible without hover or selection.

### Cards / Containers

- **Corner Style:** 10px to 14px radius.
- **Background:** Paper on Workbench.
- **Shadow Strategy:** flat by default.
- **Border:** 1px Hairline.
- **Internal Padding:** 16px compact, 24px primary.

### Inputs / Fields

- **Style:** Paper background, Hairline border, 6px radius, 36px minimum desktop height.
- **Focus:** Tool Indigo border and 2px focus ring.
- **Error / Disabled:** explicit helper text and semantic state, never color alone.

### Navigation

Dark product header, compact system labels, predictable active fill, visible focus, wrapping at medium widths, and contained horizontal scrolling only when wrapping cannot preserve labels.

### Dashboard Summary

One grouped overview band with aligned metric cells and dividers replaces a wall of identical cards. Values stay compact; long technical values wrap safely.

## 6. Do's and Don'ts

### Do:

- **Do** keep default workspace light using Workbench and Paper.
- **Do** use 4px and 8px spacing increments with larger section breaks.
- **Do** keep every control keyboard reachable with visible focus.
- **Do** test 320px, 375px, 768px, 1024px, and 1440px widths.
- **Do** use semantic HTML and native WordPress controls where they remain accessible.
- **Do** expose loading, success, error, disabled, empty, and long-content states.

### Don't:

- **Don't** build generic AI SaaS dashboards from identical oversized metric cards.
- **Don't** use giant type that reduces useful information density.
- **Don't** use dark-first, neon, gradient, glassmorphism, or ornamental interfaces.
- **Don't** ship low-contrast pills, hidden overflow, cramped controls, or unexplained status color.
- **Don't** use colored side stripes on cards, callouts, notices, or errors.
- **Don't** use display fonts in labels, controls, tables, or data.
- **Don't** use `transition: all`, remove focus without replacement, or animate layout properties.
- **Don't** ship project-specific names, URLs, memory, audit data, or credentials as product defaults.

## 7. Admin Surface Audit

This is the release checklist for every Stonewright-owned wp-admin surface.

| Surface | Primary job | Visual contract | Risk to re-check |
| --- | --- | --- | --- |
| Dashboard | Scan current operating state | One compact summary band, two evidence panels, metrics at 20px or below | Long mode, URL, and activity values must wrap without changing cell width |
| Setup | Connect and update safely | Numbered steps, grouped choices, readable code, explicit verification receipts | Release checks must bypass stale caches; secrets never enter examples |
| Troubleshoot | Diagnose a failed AI client connection | Diagnostic cards, status pills, in-place Run diagnostics with a loading spinner | JavaScript path must not reload the page; no-JS form remains a fallback |
| AI Abilities | Search and gate tools | Sticky filters, compact grouped rows, semantic switches and buttons | Category actions must not depend on inline click handlers |
| Sandbox | Review code before activation | Clear tabs, readable file badges, explicit primary/destructive actions | Status text and payloads must remain legible at narrow widths |
| Design Studio | Capture and validate direction | Same sans hierarchy and shared tokens as the rest of the product | Rich editing must not introduce a second visual language |
| Visual Workspace | Inspect and edit visually | Stable canvas/inspector hierarchy, shared controls and focus rings | Drawer, canvas, and technical values must stay usable at 320px |
| Blueprints | Choose repeatable structures | Compact catalog cards, restrained metadata, progressive detail | Industry, mode, and hash chips must remain at least 12px |
| Prompts | Find a safe task starter | Search-first catalog, grouped outcomes, copy action | Long prompt/tool text must wrap inside its surface |
| Code Approval | Issue a scoped grant | Clearly labeled token, copy action, binding receipt | Token content must never be obscured, logged, or persisted accidentally |
| Audit Log | Diagnose and understand outcomes | Filter panel, responsive rows, full-width readable payload | Payload, cause, and repair copy must never escape or collapse the table |
| Memory | Manage durable site knowledge | Compact table, explicit lifecycle/status, edit/delete actions | Fresh installs start empty; updates preserve user data |
| Context | Persist operator Context for every MCP agent | Two-column system/user layout, collapsible generated snapshot | Compact task-start must carry truncated user context text |
| Design | Persist the active Design Direction | Shared tokens and compact direction list/editor | Compact task-start points at design-direction-brief; do not inline the full contract |
| Skills | Manage reusable instructions | Catalog/editor split, clear provenance and lifecycle | Fresh installs include only product defaults; user data survives updates |

### Cross-page release checks

- Load every page with the shared shell and no console errors.
- Test 320px, 375px, 768px, 1024px, and 1440px widths with zero product-level horizontal overflow.
- Verify default, hover, focus-visible, disabled, loading, success, warning, error, empty, and long-content states.
- Keep body copy at 12px or larger and page titles at 24px.
- Use one light token system; page styles may extend layout, never redefine the palette.
- Treat plugin version, release version, configured bridge version, and live companion version as different facts.
