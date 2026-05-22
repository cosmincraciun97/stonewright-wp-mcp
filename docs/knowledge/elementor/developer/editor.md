---
title: The Editor
source_url: https://developers.elementor.com/editor/
fetched_at: 2026-05-22T16:00:00Z
content_hash: sha256-pending
applies_to: [developer]
related_widgets: []
---

## Purpose
The Elementor Editor documentation provides an overview of the core editing environment where developers build pages and manage site settings. It covers the structural components — preview and panel areas — and introduces the various panel types that developers can extend to customize the editing experience.

## Use this when
- Building custom panels or extending existing Elementor editor functionality
- Integrating third-party controls into the editor interface
- Creating custom page, site, or user preference settings
- Developing widgets that require panel-level customization
- Understanding the architectural layout of the Elementor editing environment

## API highlights
- **Elementor Preview** — the visual rendering area where page content displays
- **Elementor Panel** — the sidebar containing controls and settings
- **Menu Panel** — top-level navigation and file operations
- **Site Settings Panel** — global website configuration controls
- **User Preferences Panel** — individual user customization options
- **Page Settings Panel** — page-specific metadata and configuration
- **History Panel** — undo/redo and revision management
- **Widgets Panel** — available widget library for drag-and-drop composition
- **Default Panel** — extensibility entry point for custom panels
- **Elementor Tabs** — tabbed interface organization within panels

## Limits / gotchas
- The editor "consists of two main areas" (preview and panel) with multiple panel types; developers must target the correct panel for their use case
- Extension capabilities are specifically documented under "Extending The Editor," suggesting only designated extension points are officially supported
- "Elementor Core Basic" designation indicates tier-specific feature availability; higher versions may include additional extension points
- Panel architecture is hierarchical, requiring understanding of parent-child relationships when injecting custom controls
