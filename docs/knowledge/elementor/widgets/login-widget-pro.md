---
title: Login widget
source_url: https://elementor.com/help/login-widget-pro/
fetched_at: 2026-05-22T15:30:00Z
content_hash: sha256-pending
applies_to: [widget:login]
related_widgets: [form, template]
---

## Purpose
The Login widget (Elementor Pro) renders a styled WordPress authentication form directly on any Elementor-designed page, replacing the default `/wp-login.php` form. It supports custom redirect URLs after successful login, lost password links, and registration links, all styled within the Elementor design system.

## Use this when
- Building a branded login page that matches site design instead of bare WordPress default
- Creating members-only landing pages where login is embedded in the hero section
- Designing a popup or modal that contains the login form
- Needing to redirect users to a specific page (dashboard, account, custom URL) after login

## Settings highlights
- **Username Label** / **Password Label**: customize field labels text
- **Remember Me**: toggle the "Remember Me" checkbox on/off
- **Button Text**: change the submit button label (default "Log In")
- **Redirect To**: choose custom URL, home page, or last visited page after successful login
- **Lost Password Link**: show/hide with customizable label and link text
- **Registration Link**: show/hide link to registration page
- **Form Fields Typography / Colors**: per-field styling
- **Button Style**: full button widget styling (background, border, hover, typography)
- **Separator Style**: divider between form and auxiliary links

## Limits / gotchas
- Requires Elementor Pro — not available in Free
- Does not include built-in two-factor authentication; requires a separate 2FA plugin
- Conflict risk with security plugins that override the login form (Wordfence, iThemes Security) — test compatibility
- Registration form functionality requires a separate Registration Form widget or a plugin like User Registration
