---
title: Variables
source_url: https://elementor.com/help/variables/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Variables in Elementor V4 are design tokens — named values for colors, font sizes, spacing, and other CSS properties — stored as CSS custom properties (`--variable-name`) and available across all style controls in the editor. They enable a design-system-driven workflow where changing a variable's value updates every element that references it, analogous to Figma variables or CSS custom property systems.

## Use this when

- Defining brand colors once and applying them across hundreds of elements
- Creating a spacing scale (4px, 8px, 16px, 24px, 32px) for consistent layout rhythm
- Maintaining a typography scale so font sizes stay proportional across breakpoints
- Enabling site-wide design changes with a single variable edit rather than element-by-element updates
- Building sites for clients where brand identity can be updated from one central location

## Settings highlights

- **Variable types** — Color, Font Size, Spacing (+ any CSS value as a custom string variable)
- **Variable name** — auto-generated kebab-case slug or custom name; generates `--elementor-variable-{name}` CSS custom property
- **Value** — set via color picker (for color variables), slider, or text input
- **Apply to prop** — reference a variable in any compatible style prop by selecting from the variable picker dropdown in the color/size field
- **Variables Manager** — dedicated panel for creating, editing, grouping, and deleting variables [[variables-manager]]
- **Responsive variables** — variables can have different values per breakpoint (e.g. different `--font-size-body` on mobile)
- **Export/Import** — variable sets can be exported as JSON for cross-site migration
- **CSS output** — variables are emitted as `:root { --elementor-variable-name: value; }` in the page stylesheet

## Limits / gotchas

- Variables in V4 are distinct from Global Colors (which are simpler named swatches); variables are CSS custom properties and support responsive breakpoint overrides
- Deleting a variable that is referenced by elements leaves dangling `var()` references; affected elements may render with no value for that prop
- Variable names must be unique across the site; duplicates are auto-resolved by appending a suffix
- CSS custom properties don't support complex computed values (e.g. `calc()` across variables from JS) without custom code
