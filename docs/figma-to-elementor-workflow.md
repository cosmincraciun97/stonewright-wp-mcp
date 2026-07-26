# Figma to Elementor with Stonewright

This is the production workflow for implementing a Figma page in Elementor V3
or V4. It works with either the official Figma MCP or Figma Console MCP because
Stonewright never consumes a vendor-specific raw tree. Figma provides evidence;
live Elementor schemas decide what can be written.

## What each part owns

| Part | Owns | Does not own |
|---|---|---|
| Figma MCP | Frames, variables, styles, text, assets, exact bounds | Elementor control names or permission to write |
| Design Studio | Site direction, tokens, dials, guidance, readiness, revisions | The content of one Elementor page |
| DesignEvidence | Measured source observations and provenance | Design preference or write authority |
| Native planner | Semantic node to native widget/container plan | Raw Elementor settings |
| Typed Elementor abilities | Schema-checked, backed-up writes and readback | Visual proof |
| Visual Workspace/browser | Live editor connection, confirmation, rendered evidence | Automatic pixel-perfect certification |

Design Library still has a separate job: reusable page/section blueprints and
brand kits. Design Studio is the active site-wide visual contract; Visual
Workspace is a per-post execution and evidence surface. Removing the library
would force reusable structures into either site direction or page history,
which is the wrong abstraction.

## Fast extraction protocol

1. Call `stonewright-task-start` for an Elementor design task.
2. Call `stonewright-design-direction-brief` once. If there is no active ready
   direction, create/review one in Design Studio before treating it as policy.
3. In the chosen Figma MCP, read page and top-level frame metadata once. Build
   an ordered section manifest: node id, name, desktop frame, mobile frame.
4. Read variables, local styles, and reusable components once. Record only the
   values used by the target page.
5. Deep-read one top-level section: semantic nodes, exact bounds, text, asset
   references/crops, auto-layout intent, and used styles.
6. Normalize immediately into DesignEvidence 1.0. Include stable source
   references and SHA-256, desktop and mobile viewports, provenance for
   concrete styles, and `measured_targets` with tolerances.
7. Discard the raw Figma response. Do not send the full document tree to a
   second Figma MCP and do not carry vendor nodes into Elementor.

Variables and page structure are read once; deep data stays bounded to the
section currently being built. Later calls reuse the compact direction brief,
evidence hash, section manifest, and live schema summaries.

## Compile and write

1. Call `stonewright-design-native-plan` with normalized evidence and target
   renderer.
2. Read the current Elementor page digest/structure and kit globals.
3. For each widget, query `stonewright-elementor-schema` in summary mode.
   Request one complete control only when the summary is insufficient.
4. Map reusable approved colors and typography to kit globals. Use
   `stonewright-design-direction-sync-plan` and explicit review before a
   site-wide sync.
5. Compile no more than two tightly coupled sections. Use
   `stonewright-elementor-v3-build-page-from-spec` with `dry_run=true` for a
   new section/page, or `stonewright-elementor-v3-batch-mutate` for surgical
   edits to an existing tree.
6. Review diagnostics and hashes, then perform the typed write. The ability
   snapshots first, preserves unknown settings, reads the effect back, and
   refuses V3 writes into V4/mixed documents.

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
  browser evidence compares the same 1440 and 390 viewports. A mismatch is
  repaired with a surgical batch mutation, not a full-tree rewrite.
- Visual Workspace connects to the actual Elementor editor window so the user
  sees adapter state, proposed diff, confirmation, and quality evidence while
  keeping the editor open.

The result can be highly faithful, but “pixel perfect” is an evidence loop, not
a one-click promise. Completion requires measured comparisons at every target
breakpoint, no horizontal overflow, correct assets/crops, interactive states,
and no unchecked critical rule.

## Admin onboarding

- **Overview** selects the direction and shows active/ready/sync state.
- **Editor** changes tokens, dials, guidance, readiness, and open issues. Save
  creates a new revision. “Ready for use” permits activation; “Ready to sync
  globals” separately permits an Elementor kit sync plan.
- **Quality** reads stored reports for a post and links to Visual Workspace.
- **History** restores an old contract as a new revision.
- **Visual Workspace** starts with a post id. Select **Connect editor**, keep
  the companion editor open, then follow connect → read → preview → confirm →
  apply → verify. Missing evidence remains `not_checked`.

Neither admin page replaces the AI client or browser measurement tool. They
make the design contract, confirmation, and evidence visible and reviewable.
