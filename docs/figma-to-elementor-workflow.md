# Figma to Elementor with Stonewright

This is the production workflow for implementing a Figma page in Elementor V3
or V4. It works with either the official Figma MCP or Figma Console MCP because
Stonewright never consumes a vendor-specific raw tree. Figma provides evidence;
live Elementor schemas decide what can be written.

## What each part owns

| Part | Owns | Does not own |
|---|---|---|
| Figma MCP | Frames, variables, styles, text, assets, exact bounds | Elementor control names or permission to write |
| Design direction (MCP) | Site direction, tokens, dials, guidance, readiness, revisions via typed design abilities | The content of one Elementor page |
| DesignEvidence | Measured source observations and provenance | Design preference or write authority |
| Native planner | Semantic node to native widget/container plan | Raw Elementor settings |
| Typed Elementor abilities | Schema-checked, backed-up writes and readback | Visual proof |
| Visual Workspace/browser | Live editor connection, confirmation, rendered evidence | Automatic pixel-perfect certification |

Blueprints and brand kits remain available as typed MCP abilities (the Design
Library admin UI is disabled). Design direction abilities are the site-wide
visual contract; page-local QA uses Visual Workspace tooling when the visual
package is present.

## Fast extraction protocol

1. Call `stonewright-task-start` for an Elementor design task.
2. Call `stonewright-design-direction-brief` once. If there is no active ready
   direction, create/review one via design-direction MCP abilities before treating it as policy.
3. In the chosen Figma MCP, read page and top-level frame metadata once. Build
   one page manifest with an ordered `sections` array: stable section and node
   IDs, exact source order (or a unique explicit integer `order`), desktop and
   mobile frames, positive bounds, roles, and provenance. Validate it with
   `stonewright-design-section-manifest`, then use `action: decompose`; do not
   reconstruct section order from names.
4. Read variables, local styles, and reusable components once. Record only the
   values used by the target page.
5. Deep-read one top-level section: semantic nodes, exact bounds, text, asset
   references/crops, auto-layout intent, and used styles.
6. Build a compact section contract before any write: source viewport,
   `element_id`, exact setting/control, desktop/tablet/mobile value, tolerance,
   and expected rendered effect. Record outer and content-frame bounds
   separately; a boxed Elementor container renders content through
   `.e-con-inner`.
7. Normalize immediately into DesignEvidence 1.0. Include stable source
   references and SHA-256, desktop and mobile viewports, provenance for
   concrete styles, and `measured_targets` with tolerances.
8. Discard the raw Figma response. Do not send the full document tree to a
   second Figma MCP and do not carry vendor nodes into Elementor.

For a carousel, the section contract records slide count, gap, arrows, and dots
for all three target devices. Do not fill unknown loop, autoplay, pause, swipe,
keyboard, RTL, size, or hit-area values with guessed defaults. Active arrows
need previous and next accessibility labels plus exactly one asset source each:
WordPress media, an installed renderer icon, a normalized manifest asset, or
inline SVG. Inline SVG with scripts, events, CSS imports, external URLs,
entities, or unsupported DOM nodes is rejected. When arrows are disabled at
every viewport, no arrow asset is required.

Variables and page structure are read once; deep data stays bounded to the
section currently being built. Later calls reuse the compact direction brief,
evidence hash, section manifest, and live schema summaries.

## Compile and write

1. Call `stonewright-design-native-plan` with normalized evidence and target
   renderer.
2. Read the current Elementor page digest/structure and kit globals.
3. For each widget, query `stonewright-elementor-schema` in summary mode.
   Request one complete control only when the summary is insufficient. Feed
   section-manifest planning registered candidates with their real schema hash,
   controls, capabilities, and explicit capability mappings; a missing schema
   is a native gap, not permission to guess a widget name.
4. Map reusable approved colors, typography, container width, and layout
   defaults to kit globals. Use `stonewright-design-direction-sync-plan` for
   the review plan, then `stonewright-elementor-v3-kit-batch-mutate` for one
   typed dry-run and one apply. The kit writer serializes against other
   Elementor writes, snapshots first, preserves unknown settings, verifies
   readback, invalidates kit cache, and supports explicit snapshot rollback.
5. Compile no more than two tightly coupled sections. Use
   `stonewright-elementor-v3-build-page-from-spec` with `dry_run=true` for a
   new section/page, or `stonewright-elementor-v3-batch-mutate` for surgical
   edits to an existing tree.
6. Review diagnostics, `schema_requests`, safe V3 roots, and hashes, then
   perform one consolidated typed write for the post. Writes to one Elementor
   document are sequential; never race them in parallel. The ability
   snapshots first, preserves unknown settings, reads the effect back, and
   refuses V3 writes into V4/mixed documents.
7. Call `stonewright-elementor-post-write-verify` with the touched IDs. It
   invalidates post-scoped generated state, regenerates post CSS, warms the
   public Elementor frontend renderer, and asserts the bounded targets.
8. Measure and capture the logged-out frontend at desktop, tablet, and mobile.
   For boxed containers measure outer, `.e-con-inner`, and the first semantic
   child. For carousels record card width, gap, visible count, and peek pixels.
   Compare bounded anchors for geometry, typography, line-height, spacing, and
   color. A missing observed measurement is a failure, not zero. The write is
   not complete until these checks pass.

Native CTA and testimonial/review carousel nodes are emitted only when their
widgets are registered in the live Elementor runtime and the required skin or
repeater controls are present. Button icons likewise use only the live
`selected_icon` or legacy `icon` control plus supported position/spacing
controls. Catalog-only Pro entries produce diagnostics and never authorize a
lower-fidelity substitution.

For a new identity, replacement, or rebrand, build only the first section.
Render it, collect desktop/tablet/mobile evidence, obtain explicit approval,
record `stonewright-design-checkpoint-record`, then pass its token to later
section writes.

## Realistic landing-page use case

Suppose the Figma file contains 1440 px desktop and 390 px mobile frames:
header, split hero, proof strip, asymmetric services, editorial case study,
testimonial, CTA, and footer.

- The shallow read produces eight section ids and two viewports.
- The token read produces approved color/type/spacing tables once.
- Hero evidence records a 1240 px inner width, 72 px desktop padding, a 56/60
  display style, exact column ratio, image crop, button states, and mobile
  stacking.
- The direction brief says medium density, high variance, and low motion. The
  compiler uses declared spacing tokens, preserves the measured asymmetric
  split, and omits entrance/motion-effect settings.
- The native planner selects containers, Heading, the correct V3 text widget
  or V4 atomic paragraph, Button, and Image. Decorative Figma groups do not
  become empty Elementor containers.
- The dry run exposes the exact tree and diagnostics. After the guarded write,
  the post-write verifier closes cache/CSS/frontend assertions and browser
  evidence compares the same 1440 and 390 viewports. A mismatch is repaired
  with a surgical batch mutation, not a full-tree rewrite.
- Browser verification measures the actual Elementor frontend at every target
  breakpoint after the typed post-write verifier closes cache and readback.

The result can be highly faithful, but “pixel perfect” is an evidence loop, not
a one-click promise. Completion requires measured comparisons at every target
breakpoint, no horizontal overflow, correct assets/crops, interactive states,
and no unchecked critical rule.

## Design Library status

Design Studio, Visual Workspace, and Blueprints are not registered as wp-admin
pages in this release. Stored design directions remain intact, while agents use
typed MCP contracts and a separate browser measurement tool for reviewable
evidence. Missing evidence remains `not_checked`.

The complete cache/readback/browser closure is documented in
[Elementor write verification](elementor-write-verification.md).
