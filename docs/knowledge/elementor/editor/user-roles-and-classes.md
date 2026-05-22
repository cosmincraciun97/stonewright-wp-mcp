---
title: User roles and classes
source_url: https://elementor.com/help/user-roles-and-classes/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [editor:v4]
related_widgets: []
---

## Purpose

This article covers how WordPress user roles interact with Elementor V4's Class Manager and class-based styling system. It explains which roles can create, edit, delete, or only apply classes — enabling site owners to allow content editors to apply predefined design styles without the risk of modifying or deleting shared class definitions.

## Use this when

- Setting up a multi-editor workflow where designers own class definitions and editors only apply them
- Restricting which user roles can access the Class Manager to prevent style system breakage
- Configuring Elementor role-based access so clients can edit content but not modify global styles
- Auditing which user role created or last modified a class definition
- Building agency sites where the client should only see approved class options

## Settings highlights

- **Role Manager** — accessible in Elementor > Settings > Roles (requires Elementor Pro)
- **Editor capability** — "Design" capability grants full Style tab and Class Manager access
- **Author/Contributor** — restricted roles can apply existing classes but cannot open Class Manager or edit class definitions
- **Class visibility** — all defined classes appear in the class picker for all roles; only "Design" roles can modify them
- **Administrator** — always has full access regardless of Role Manager settings
- **Custom capabilities** — Elementor Pro allows defining custom role permission sets beyond the defaults

## Limits / gotchas

- Role-based class restrictions require Elementor Pro; free version has no Role Manager
- Role restrictions apply to the Elementor editor only — direct database or PHP access bypasses them
- If a restricted user applies a class and the class is later deleted by an admin, the element loses those styles silently
- V4 class permissions are distinct from WordPress post/page capability management; the two systems operate independently
