---
title: What is a dynamic request parameter?
source_url: https://elementor.com/help/dynamic-request-parameter-pro/
fetched_at: 2026-05-23T00:12:12.080Z
content_hash: sha256-fe9fb10d7819b1a12bce5f91a2903d2191adac10fd05a884f1ffb3a36481b360
applies_to: [editor:v3]
related_widgets: [button, icon]
harvest_source: gemini-browser
---

## Purpose
Post Request (e.g. after a user is registered in an external CRM, a custom function could be coded to populate the user’s name onto the page.)Get Request (e.g a URL parameter could populate the value of the parameter onto the page)Query Vars Request (e.g can populate a field with data from any of the WordPress default Query Variables, such as the attachment ID.)

## Use this when
- Organizing your layout design and structuring content elements inside Elementor.
- Enhancing user experience by presenting information in a clean, professional, and accessible layout.
- Customizing specific styles, responsiveness, and display logic for elements across devices.

## Settings highlights
- Note – WordPress maintains a list of reserved words that should not be used as request parameters. Doing so would result in a 404 Not Found error being returned. This is a WordPress function and is outside of Elementor’s control. See Reserved Terms list from WordPress.org documentation.
- Type – Select from Get, Post, or Query VarParameter Name: Enter your custom parameter (e.g. the_subject)
- Before – Optionally add static content before the dynamic elementAfter: Optionally add static content after the dynamic elementFallback: Optionally add default static content if the parameter does not exist.Use custom parameter as desired (e.g. Add custom parameter to button’s link)
- Content options – Configure general content, title, tags, and icons.
- Style settings – Customize colors, borders, background, padding, and typography.
- Advanced features – Apply custom CSS classes, ID, and responsiveness properties.

## Limits / gotchas
- Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.
- Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.
