---
title: Custom attributes
source_url: https://elementor.com/help/custom-attributes-pro/
fetched_at: 2026-05-23T00:16:42.516Z
content_hash: sha256-b99459e51e73efc89952aa4c0778764d752f01e9a1c61d56c62038268edce44e
applies_to: [widget:custom-attributes-pro]
related_widgets: [button]
harvest_source: gemini-browser
---

## Purpose
With Elementor Pro, you can add custom attributes to the wrapper of every Section, Column or Widget. This enables the addition of data-* attributes, ARIA attributes (accessibility) and values, header, footer, sidebar, rel=*, and other attributes that can be found here: https://www.tutorialspoint.com/html5/html5_attributes.htm

## Use this when
- Organizing your layout design and structuring content elements inside Elementor.
- Enhancing user experience by presenting information in a clean, professional, and accessible layout.
- Customizing specific styles, responsiveness, and display logic for elements across devices.

## Settings highlights
- Right – click on the edit button of the element and click Edit section to open the section’s settings panel. Likewise, if editing a Widget, right-clicking will show the option to Edit Widget, and editing a Column will show Edit Column.Go to Advanced > AttributesAdd your code for the element to the editor, using the format key|value. For example, to add role=”presentation” to the element’s HTML, enter role|presentation here.
- Tip – Set custom attributes for the wrapper element, with each attribute in a separate line. Separate attribute key from the value using the | character. If you need to add multiple properties for one attribute, use a space between them.
- data – spots|round
- data – spots|round long
- In this example, data – spots is our custom attribute, and round and long are the properties of the attribute. When you view the source code that is output, it will look like this:
- data – spots=”round long”

## Limits / gotchas
- Prerequisites: Ensure you are using the correct Elementor and Elementor Pro versions compatible with this feature.
- Performance: Having too many nested elements or widgets on a single page can affect site speed and core web vitals.
