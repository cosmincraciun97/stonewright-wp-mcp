---
title: Explore the V4 features
source_url: https://elementor.com/help/explore-the-v4-features/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

This overview article presents the full feature set introduced with Elementor Editor V4, orienting users coming from V3 to the new atomic element model, unified Style tab, class and variable systems, interactions, and responsive editing improvements. It serves as the entry point for V4 discovery before diving into individual feature articles.

## Use this when

- You are new to V4 and need a feature map before building
- Migrating a project from V3 and need to understand what changed and what is new
- Evaluating whether to enable V4 mode for an existing site
- Onboarding team members unfamiliar with the V4 interface

## Settings highlights

- **Atomic elements** — Button, Heading, Paragraph, Image, SVG, YouTube, Div Block, Flexbox, Grid, Tabs replace or supplement legacy widgets
- **Unified Style tab** — all styling in one tab organized as: Layout, Size, Position, Spacing, Background, Border, Effects, Typography
- **Element states** — hover, active, focus, disabled style overrides without custom CSS
- **Classes** — reusable named style sets via the Class Manager panel
- **Variables** — design tokens (color, spacing, font-size) managed in Variables Manager
- **Interactions** — trigger-based animations and actions (scroll-into-view, click, hover) without JS
- **Responsive editing** — per-breakpoint overrides for every style prop with visual breakpoint bar
- **Logical properties** — RTL-aware margin/padding using `inline-start/end` and `block-start/end`
- **Custom CSS** — per-element scoped CSS block in Advanced tab
- **Dynamic tags in V4** — updated binding API for template-driven content

## Limits / gotchas

- V4 is not a drop-in replacement for V3; some V3 widget behaviors are not replicated in V4 atomic elements
- Third-party add-on widgets built for V3 may need updates to render correctly in V4 editor
- The V4 editor UI looks significantly different; team training time should be factored into project plans
- Interactions in V4 are limited to built-in trigger/action pairs; complex JS-dependent animations still require custom code
