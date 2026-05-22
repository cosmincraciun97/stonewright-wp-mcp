---
title: The Elementor Editor Class Manager
source_url: https://elementor.com/help/the-elementor-editor-class-manager/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

The Class Manager is a V4 editor panel that provides a central view of all named CSS classes defined on the site, their style definitions, which elements use them, and management actions (rename, edit, delete, export). It enables design-system-like workflows where style changes to one class propagate to every element using it.

## Use this when

- Reviewing all defined classes across the site to understand the design system
- Bulk-editing a class's style so all elements using it update simultaneously
- Renaming a class to match a new naming convention without manually updating each element
- Deleting obsolete classes and checking which elements will be affected
- Exporting class definitions to import into another site or share with a team

## Settings highlights

- **Access** — editor toolbar icon (class icon) or via Style tab class field → "Manage Classes"
- **Class list panel** — lists all site-wide classes with name, element count, and preview swatch
- **Class editor** — click any class to open its Style definitions; same controls as element Style tab
- **Usage indicator** — shows how many elements reference each class; click to highlight them in the canvas
- **Rename class** — inline rename updates all references automatically without breaking element assignments
- **Delete class** — removes class definition; all elements that used it lose those styles (fallback to element inline or global)
- **Export/Import** — export class JSON for migration; import merges classes from another site's export
- **Merge classes** — combine two classes into one (duplicate styles resolved by last-write-wins)

## Limits / gotchas

- Class Manager is V4-only; it does not appear in the V3 editor interface
- User role access to Class Manager can be restricted (admins only) to prevent non-technical editors from breaking the design system
- Deleting a class is irreversible from within Class Manager; undo is available only immediately after via editor undo history (Ctrl+Z)
- Classes store style values, not dynamic or conditional logic; for conditional styling use element states or custom CSS
