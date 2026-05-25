# Refinement Checklist

Run through this list when producing a review report.

## Structural checks

- [ ] All spec sections have a corresponding live element (container/block/template)
- [ ] Section order on the live page matches spec sections order
- [ ] No unexpected sections present on the live page (flag but do not auto-delete)
- [ ] Page title matches `spec.page.title`
- [ ] Page slug matches `spec.page.slug` (if specified)

## Token checks

- [ ] Kit primary color matches `spec.tokens.colors.primary`
- [ ] Kit secondary color matches `spec.tokens.colors.secondary`
- [ ] Kit accent color matches `spec.tokens.colors.accent` (if present)
- [ ] Kit heading font family matches `spec.tokens.typography.heading.font_family`
- [ ] Kit body font family matches `spec.tokens.typography.body.font_family`
- [ ] Global styles palette (FSE) matches spec tokens (for block theme sites)

## Asset checks

- [ ] Every image referenced in the spec is present in the media library
- [ ] Every image on the live page has a non-empty alt attribute
- [ ] Hero/feature images match the spec asset references

## Layout checks

- [ ] Hero section background color / image matches spec
- [ ] Container padding values are within 4px of spec values (minor drift is ok)
- [ ] Column width ratios match spec layout hints
- [ ] Mobile breakpoint layout is reasonable (not prescriptive unless spec defines it)

## Accessibility checks

- [ ] Page has exactly one h1
- [ ] Heading hierarchy is sequential (no level skips)
- [ ] All images have alt text (decorative images have empty alt)
- [ ] All buttons and links have accessible names
- [ ] Color contrast meets WCAG AA (4.5:1 for normal text, 3:1 for large text)

## Spec version

- [ ] `spec.version` is present and matches the plugin's supported spec version
- [ ] `spec.source` references the image, brief, URL, or manual source used as input (for audit trail)

## After applying fixes

Re-run `design-validate-spec` on the current spec to confirm it is still
well-formed. Re-check the relevant sections of the live page to confirm each
fix landed correctly.
