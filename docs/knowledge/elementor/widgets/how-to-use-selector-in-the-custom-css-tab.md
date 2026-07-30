---
title: Use selector In the custom CSS tab
source_url: https://elementor.com/help/how-to-use-selector-in-the-custom-css-tab/
fetched_at: 2026-05-23T00:20:53.300Z
content_hash: sha256-62ca9fae09dc1556924bd3c5e718a254e2ca9195f46c3e9809c781bb14e8f09a
applies_to: [widget:how-to-use-selector-in-the-custom-css-tab]
related_widgets: [button, image]
harvest_source: gemini-browser
---

## Purpose
For example, if you’ve placed an image (or any child element) in a column, you may want to style either the wrapper surrounding the image, or the image itself.

## Use this when
- Organizing your layout design and structuring content elements inside Elementor.
- Enhancing user experience by presenting information in a clean, professional, and accessible layout.
- Customizing specific styles, responsiveness, and display logic for elements across devices.

## Settings highlights
- selector { border – 5px solid red; }
- selector img { border – 5px solid red; }
- selector { background – color: #ffff00; }
- selector .elementor – button { background-color: #ffff00; }
- .so – yellow .elementor-button { background-color: #ffff00; }
- .so – yellow .elementor-button { background-color: #ffff00 !important; }
- selector .elementor – button { background-color: #ffff00; color: #000000; border: 2px solid #000000; }
- Tip – For a list of Class names, see Frank Tielemans’ excellent Widget Classname Reference Guide

## Limits / gotchas
- Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.
- Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.
