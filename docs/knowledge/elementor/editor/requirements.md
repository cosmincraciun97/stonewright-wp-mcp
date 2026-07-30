---
title: System Requirements to Use Elementor
source_url: https://elementor.com/help/requirements/
fetched_at: 2026-05-23T00:11:00.092Z
content_hash: sha256-4488f27d862cebfd79fb74039a3ffd8a53369a8e2a2e0b93e3c4c773574f5f18
applies_to: [editor:v3]
related_widgets: []
harvest_source: gemini-browser
---

## Purpose
Our main goal at Elementor is to create the fastest, most advanced website builder for WordPress. To achieve this, we make sure it is based on the latest technology available.

## Use this when
- Organizing your layout design and structuring content elements inside Elementor.
- Enhancing user experience by presenting information in a clean, professional, and accessible layout.
- Customizing specific styles, responsiveness, and display logic for elements across devices.

## Settings highlights
- Note – These requirements are for Elementor. If you are using additional plugins on your site that also have minimum requirements such as WooCommerce, you may need to increase your memory to 512 MB to help avoid loading issues. See also, the following documentation Elementor Widget Panel Not Loading.
- Note – No versions of Internet Explorer are supported. If IE support is critical for you, you may need to hire a developer to add custom code.
- Note for Elementor hosted websites – To help secure your website, Elementor requires browsers have a minimum TLS version 1.2 in order to visit Elementor hosted websites. This means that some old browsers may have trouble accessing these sites. If this is an issue for you, contact support to adjust the TLS settings. Elementor Cloud websites with custom domain names are unaffected by this change.
- CSP – frame-ancestors
- Content – Security-Policy: frame-ancestors none setting breaks Elementor Editor because it blocks the <iframe id=”elementor-preview-iframe”> that is needed for the Editor preview. This should be set to Content-Security-Policy: frame-ancestors 'self' to avoid editing issues. Please ask your host to do this for you.
- PHP Z – Lib Extension

## Limits / gotchas
- WP Memory limit of 256 MB (Elementor and Elementor Pro only), 512 MB recommended, 768 MB for best performance.
- No versions of Internet Explorer are supported. If IE support is critical for you, you may need to hire a developer to add custom code.
