---
title: Classes in Elementor
source_url: https://elementor.com/help/classes-in-elementor-2/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

Classes in Elementor V4 are reusable style definitions that can be applied to multiple elements across pages and templates. A class stores a set of style properties (typography, color, spacing, etc.) and when the class is updated, all elements referencing it update simultaneously — enabling a design-system-like workflow without code.

## Use this when

- Creating consistent button, heading, or card styles applied across dozens of elements
- Implementing a design system where style updates propagate globally
- Reducing repetitive per-element style configuration
- Building sites where non-technical editors can apply predefined styles without breaking the design
- Collaborating with developers who need predictable class names for custom CSS targeting

## Settings highlights

- **Class creation** — add class name in the element's class field (Style tab header area in V4); first save creates the class definition
- **Class Manager** — dedicated panel accessible from the editor toolbar to view, edit, rename, and delete all classes
- **Style inheritance** — element-level overrides layer on top of class styles (element > class > global)
- **Class scope** — classes are site-wide; changes affect every element using that class
- **Multiple classes** — an element can have multiple classes applied simultaneously
- **Class export/import** — export class definitions as JSON for migration between sites
- **User roles** — class management access can be restricted by user role (editor vs admin)
- **Hover/state styles** — classes can include state-specific style overrides (hover, active)

## Limits / gotchas

- V4 classes differ from V3 global widgets; classes store styles only, not structure
- Class name must be a valid CSS identifier (no spaces, must start with letter or underscore)
- Deleting a class removes styles from all elements using it — cannot be undone without revision history
- Classes and CSS custom properties (variables) are separate systems; classes store computed values, variables store tokens
