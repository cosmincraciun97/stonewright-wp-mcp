---
title: Style tab - Position
source_url: https://elementor.com/help/style-tab-position/
fetched_at: 2026-05-23T00:14:06.738Z
content_hash: sha256-708935f9f11045d9180f7d894835c46af38888795a4f278e948a0455ea955877
applies_to: [editor:v4]
related_widgets: [heading, image]
harvest_source: gemini-browser
---

## Purpose
Now let’s place an SVG element in Div Block 2 with an Absolute top position of 120 PX, placing it in the lower left of Div 2.

## Use this when
- Create a page with a sticky header and two containers below
- The menu contains an item labeled Anchor which is linked to the second container
- The second container is underneath the first container
- It is not currently visible

## Settings highlights
- Static (The default value) – The element stays where you place it. If you select Static, there is no option to change the element’s position.
- Relative – The element’s position relative to its original position.
- Absolute – The element’s position relative to the closest parent container whose position is set as Relative. If the element does not have any parent containers that are Relative, the Absolute position is relative to the webpage.
- Example – In this example, we start with a Div Block inside a Div Block.
- Set the Z – index to 1 or more.
- Fixed – The element maintains a “fixed” position on the page. As you scroll up and down the page, the element stays in the same place, but you can use number fields to adjust the element’s position.
- Sticky – The element will remain onscreen as visitors scroll down the page, but unlike , the Fixed setting discussed above the Sticky setting is relative to its parent, so that as visitors scroll down the screen it remains fixed within the parent parent but exits the screen when the parent scrolls out of view. To make an element sticky: Set the Position of the heading to Sticky.
- Example – In this example, the image is sticky to the top container but when that container scrolls out of site, it disappears.

## Limits / gotchas
- Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.
- Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.
