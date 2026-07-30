---
title: Units of measurement
source_url: https://elementor.com/help/whats-the-difference-between-px-em-rem-vw-and-vh/
fetched_at: 2026-05-23T00:20:48.945Z
content_hash: sha256-2bde8014cbec88e6f15f9bf082d46a77e896263f982ee1530778b54d0c283340
applies_to: [widget:whats-the-difference-between-px-em-rem-vw-and-vh]
related_widgets: [heading, icon]
harvest_source: gemini-browser
---

## Purpose
When designing with Elementor, you may notice that some elements have options for sizing, allowing you to choose PX, EM, REM, %, VW, or VH. But what do those options actually mean, and when should you use one over another?

## Use this when
- Unlike PX, relative units like %, EM, and REM are better suited to responsive design and also help meet accessibility standards
- Relative units scale better on different devices because they can scale up and down according to another element’s size
- Ok, great, but what if either you or the user changes the default size
- Because these are relative units, the final size values will be based off of the new base size

## Settings highlights
- REM – Relative to the root element (HTML tag)
- Column Widths – If you edit the layout of an Elementor Column, you’ll notice that there is only one width sizing unit available – %. Column widths only work well and responsively when using percentages, so no other option is given.
- Margins – A section’s margins can be specified either in PX or %. Using % is usually preferable to ensure the margins don’t get larger than the content when scaling down for a mobile device for instance. By using a percentage of the width of the device, your margins will remain relative to the size of the content, which is almost always preferable.
- Padding – A section’s padding can be specified either in PX, EM, or %. As with margins, it is often preferable to use either EM or % so the padding remains relative as the size of the page scales.
- Font Size – If you edit the typography of an element, such as a Heading, you’ll see four choices: PX, EM, REM, and VH

## Limits / gotchas
- that you could also set specific font size PX values per device by using the Device Icons to specify a size for Desktop, Tablet, and Mobile. But that still places limits on responsiveness and accessibility, so keep that in mind if you choose PX.
- Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.
- Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.
