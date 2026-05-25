# Design Spec: nZEB Expo Exhibitor Landing Page

This document defines the technical design and layout for the new exhibitor landing page in Elementor on `http://mcp-test.local/`.

## General Specifications

* **Target Page:** A new WordPress Page (Page Title: `Devino Expoyant`, Slug: `devino-expozant`).
* **Page Layout:** `Elementor Canvas` (completely removes the theme's header and footer).
* **Styling & Theme:**
  * **Background:** An exact image background extracted from the Figma design, set on the main section/container.
  * **Color Palette:**
    * Deep Dark Violet/Blue (Background): `#090b20` / `#0a0b1e`
    * Vibrant Accent Yellow (Buttons): `#FFE600`
    * High Contrast White (Headings): `#FFFFFF`
    * Gray/Muted Slate (Subtext): `#A0AEC0`
  * **Typography:** `Outfit` (or fallback `Inter`), with precise weights and letter-spacing matching the design.
  * **Responsive Design:** Native Elementor breakpoints for Desktop, Tablet, and Mobile.

---

## Page Layout & Elements (Flexbox Containers)

### 1. Main Hero Container (Section 1)
* **Type:** Flexbox Container (Column direction, centered alignment).
* **Elements:**
  * **Heading Widget:** 
    * HTML Tag: `h1`
    * Content: `Devino expozant la nZEB Expo`
    * Style: White (`#ffffff`), `Outfit` (or `Inter`), ~48px desktop / ~32px mobile.
  * **Text Editor Widget:**
    * Content: `Prezintă-ți soluțiile și conectează-te direct cu profesioniști din industrie.`
    * Style: Gray (`#a0aec0`), centered alignment, ~18px desktop / ~16px mobile.

### 2. Benefits Grid Container (Section 2)
* **Type:** Flexbox Container (Row direction, wrap enabled, gap: 24px, centered).
* **Children:** 4 separate benefit card containers.
* **Each Card Container:**
  * Width: 23% (Desktop), 48% (Tablet), 100% (Mobile).
  * Background: Slightly lighter translucent blue/dark violet (`rgba(255, 255, 255, 0.03)`), border: `1px solid rgba(255, 255, 255, 0.05)`, border-radius: `12px`, padding: `24px`.
  * **Elements within Card:**
    * **Image/Icon Widget:** Custom SVG icon downloaded from the Figma design, wrapped in a circle (using padding/background on container or widget).
    * **Heading Widget (h4):** Beneficiu title (e.g. `Vizibilitate în industrie`, `Lead-uri relevante`, `Networking`, `Poziționare ca expert`).
    * **Text Editor Widget:** Description text (e.g. `Expune-ți produsele în fața unei audiențe relevante`).

### 3. Application Form Container (Section 3)
* **Type:** Flexbox Container (Column direction).
* **Divider with Label:**
  * **Label Heading Widget:** Content: `ÎNSCRIE-TE`. Small, uppercase, centered, colored blue/light gray.
  * **Divider Widget:** Thin underline matching the design.
* **Form Heading Widget (h2):**
  * Content: `Solicită participarea`
  * Style: Centered, white, Outfit/Inter, ~36px desktop.
* **Form Widget (Native Elementor Pro Form):**
  * Never use HTML widgets! Form built with native controls.
  * **Fields:**
    * **Company Name:** Text, placeholder: `Ex: Compania Mea SRL`, width: 50% (Desktop) / 100% (Mobile).
    * **Contact Person Name:** Text, placeholder: `Ex: Ion Popescu`, width: 50% (Desktop) / 100% (Mobile).
    * **Email:** Email, placeholder: `email@companie.ro`, width: 50% (Desktop) / 100% (Mobile).
    * **Phone:** Tel, placeholder: `+40 7XX XXX XXX`, width: 50% (Desktop) / 100% (Mobile).
    * **Domain of Activity:** Text, placeholder: `Ex: Sisteme HVAC, Panouri fotovoltaice`, width: 100% (Desktop/Mobile).
    * **Message:** Textarea, placeholder: `Detalii despre produsele/serviciile pe care doriți să le expuneți...`, width: 100% (Desktop/Mobile).
  * **Submit Button:**
    * Text: `Trimite solicitarea`
    * Style: Centered, background yellow (`#FFE600`), text color black, border-radius `4px`, Outfit/Inter bold.
  * **Subtext:**
    * Text: `Echipa nZEB Expo te va contacta pentru detalii despre participare.`
    * Style: Centered, small, light-gray, placed directly below the button.

---

## Design Spec Verification Plan

1. Create the page via WP-CLI command `wp post create --post_title="Devino Expoyant" --post_type=page --post_status=publish --page_template=elementor_canvas`.
2. Inspect the created post, get its ID.
3. Build the page structure in Elementor using `stonewright/elementor-v3-build-page-from-spec` or sequential additions, using Flexbox containers.
4. Download the Figma SVG assets and background images, upload them via WP media library upload tool `stonewright/media-upload`, and map their URLs to the background and card icons.
5. Verify page layout natively.
