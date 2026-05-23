---
title: Add entrance animations to your page
source_url: https://elementor.com/help/entrance-animations/
fetched_at: 2026-05-23T00:15:13.287Z
content_hash: sha256-9db6e9e4a15914c56d695a7ff0cad7115b18347a4c127df42fec65f2e165610c
applies_to: [widget:entrance-animations]
related_widgets: []
harvest_source: gemini-browser
---

## Purpose
The Entrance Animations feature lets you animate your Widgets, Sections, and Columns. This way, as your site visitor scrolls down the page, the elements appear with an entrance animation.

## Use this when
- Organizing your layout design and structuring content elements inside Elementor.
- Enhancing user experience by presenting information in a clean, professional, and accessible layout.
- Customizing specific styles, responsiveness, and display logic for elements across devices.

## Settings highlights
- Right – click the Section, Column, or Widget’s handle to edit the element.Go to the element’s Advanced > Motion Effects tab.Choose an animation from the Entrance Animation dropdown selections. Note that you can choose your Entrance animation, including “None”, per device.
- Fading – Fade in, fade in up, down, left, rightZooming: Zoom in, zoom in up, down, left, right,Bouncing: Bounce in, bounce in up, down, left, rightSliding: Slide in up, down, left, rightRotating: Rotate in, rotate in down left, down right, up left, up rightAttention seekers: Bounce, flash, pulse, rubber band, shake, head shake, swing, tada, wobble, jelloLight speed: Light speed inSpecials: Roll in
- Note – Elementor respects the “reduced motion property” preference that a user may set. If a user has set any of the following, then motion effects will be disabled for that user:
- Mac – “System Preferences > Accessibility > Display” and check/un-check the box for “Reduce motion”iOS: “Settings > General > Accessibility” and turn on/off “Reduce Motion”Windows 10: “Settings > Ease of Access > Display > Simplify and Personalise Windows” and turn on/off “Show Animations in Windows”
- Note – In Safari, if you are not seeing mouse effects, or you are experiencing the problem of elements disappearing in Safari, this is due to an old jQuery version being used by WordPress. In some cases, viewing Mouse Track effects via Safari might cause a jQuery error which will cause elements to disappear, such as missing carousel arrows, for example. If this happens, you may also see an error which references “maximum call stack size exceeded”. To resolve the issue, either remove entrance animations from widgets with motion effects and/or remove mouse effects from areas that have both scrolling and mouse effects simultaneously activated.

## Limits / gotchas
- Elementor respects the “reduced motion property” preference that a user may set. If a user has set any of the following, then motion effects will be disabled for that user:
- Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.
- Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.
