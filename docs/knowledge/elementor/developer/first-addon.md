---
title: Your First Addon
source_url: https://developers.elementor.com/getting-started/first-addon/
fetched_at: 2026-05-22T16:00:00Z
content_hash: sha256-pending
applies_to: [developer]
related_widgets: []
---

## Purpose
This guide demonstrates how to create a basic Elementor addon by building two simple widgets that extend Elementor's functionality. It covers the fundamental plugin structure, widget registration via the `elementor/widgets/register` hook, and implementing both static and configurable widget outputs with controls.

## Use this when
- Building your first Elementor widget or addon plugin
- Learning the standard folder structure for Elementor extensions
- Implementing widgets with custom controls (text input, color picker)
- Understanding the widget lifecycle (render, content_template, register_controls)
- Registering multiple widgets within a single addon

## API highlights
- **Hook**: `elementor/widgets/register` — lifecycle event for widget registration
- **Base class**: `\Elementor\Widget_Base` — extend this for custom widgets
- **Key methods**: `get_name()`, `get_title()`, `get_icon()`, `get_categories()`, `get_keywords()`, `register_controls()`, `render()`, `content_template()`
- **Control registration**: `start_controls_section()`, `add_control()`, `end_controls_section()`
- **Control types**: `Controls_Manager::TEXTAREA`, `Controls_Manager::COLOR`
- **Tabs**: `Controls_Manager::TAB_CONTENT`, `Controls_Manager::TAB_STYLE`
- **Widget manager**: `$widgets_manager->register(new WidgetClass())`
- **Settings retrieval**: `$this->get_settings_for_display()`
- **CSS selectors**: `{{WRAPPER}}` for scoped styling, `{{VALUE}}` for dynamic values

## Limits / gotchas
- Widgets must extend `\Elementor\Widget_Base` and implement required abstract methods
- The `render()` method outputs frontend HTML; `content_template()` uses Underscore.js syntax for live preview
- Always sanitize user input and use `esc_html__()` for translatable strings to prevent XSS
- Plugin header requires `Requires Plugins: elementor` for proper dependency management; tested versions should match current Elementor releases
