---
title: Building Addons
source_url: https://developers.elementor.com/addons/
fetched_at: 2026-05-22T16:00:00Z
content_hash: sha256-pending
applies_to: [developer]
related_widgets: []
---

## Purpose
This documentation introduces the foundational concepts for building Elementor addons — WordPress plugins that extend Elementor's core functionality. It establishes that addon development requires both WordPress plugin knowledge and Elementor-specific standards, providing a structured pathway from basic addon architecture through component extension.

## Use this when
- Building a new WordPress plugin to extend Elementor with custom functionality
- Setting up the initial addon structure following WordPress and Elementor best practices
- Planning addon architecture before implementing widgets, controls, or dynamic tags
- Establishing proper file organization, namespacing, and initialization patterns
- Ensuring compatibility checks and proper loading sequences for addon dependencies

## API highlights
- **File & Folder Structure**: Organization standards for addon directory layout
- **Header Comments**: Plugin metadata in main addon file (WordPress plugin header format)
- **Loading Process**: Hook-based initialization and script/style enqueueing
- **Main Class**: Singleton pattern wrapper class for addon functionality
- **Compatibility Checks**: Version validation against Elementor and WordPress minimums
- **Initialization Process**: Action hooks for plugin activation and feature registration
- **Namespaces**: PHP namespace organization to prevent naming conflicts
- **Extension Points**: Widgets, Controls, Dynamic Tags, Form Actions, Form Fields, Finder categories, Context Menu extensions

## Limits / gotchas
- Addon development requires prior WordPress plugin development knowledge; consult the WordPress Plugin Handbook for fundamentals
- Addons must implement "all WordPress coding standards and best practices" alongside Elementor-specific requirements
- The wrapper/foundation must be properly built before extending individual Elementor components; skipping foundational steps causes integration failures
- This covers addon scaffolding only; actual component creation (widgets, controls) requires separate specialized documentation referenced in "What's Next"
