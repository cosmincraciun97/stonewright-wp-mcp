---
title: How to Create an Archive Page with Elementor
source_url: https://elementor.com/help/create-archive-template/
fetched_at: 2026-05-23T00:07:56.410Z
content_hash: sha256-70c2f4ea16d43f621b6417c74161bb89dcb77aceb97e4a7a3261a39451e8e313
applies_to: [theme-builder]
related_widgets: [image, icon]
harvest_source: gemini-browser
---

## Purpose
Before we begin with the Elementor steps, keep in mind that WordPress plays a significant role in the Blog archive page creation. You should first publish a blank page within the normal WordPress Add Page interface (call it Blog if you’d like), and then once you’ve published that blank page, go to Settings > Reading. Now choose the page you just published in the Posts Page dropdown as shown below.

## Use this when
- You don’t have to use Elementor to write the actual content, although you can if you’d like, of course
- In this way, you can dynamically set the surrounding layout
- Organizing your layout design and structuring content elements inside Elementor.
- Enhancing user experience by presenting information in a clean, professional, and accessible layout.

## Settings highlights
- Tip – You can use the standard WordPress editor (either Classic or Gutenberg) to write your posts. You don’t have to use Elementor to write the actual content, although you can if you’d like, of course. But many people prefer to write posts the standard WordPress way, since they often don’t need the advanced layout features for a simple, straightforward blog post. No matter which way you decide to write the actual blog post content, the Elementor Archive Template will give you all the freedom you need to layout and design the Blog Archive page.
- Tip – You can create dynamic archive templates by using ACF to add custom fields to your taxonomies such as your categories and tags, and then you can display those taxonomy custom fields on your archive pages. In this way, you can dynamically set the surrounding layout. For example, if you create a custom image field for each of your categories, then your archive template could display a unique banner image for each category.
- Note – If you have created a custom post type using a plugin such as CPT UI, be sure to change ‘Has Archive’ from false to true so you can build an archive for this custom post type. If you’ve hand-coded the function yourself, be sure to set the parameter has_archive to true.
- Note – Elementor’s Archive Templates remove theme sidebars by default. Theme developers can overcome this by using Elementor’s Theme Locations API within their themes.

## Limits / gotchas
- Elementor’s Archive Templates remove theme sidebars by default. Theme developers can overcome this by using Elementor’s Theme Locations API within their themes.
- Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.
- Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.
