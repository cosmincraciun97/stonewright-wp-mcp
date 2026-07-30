# Elementor write verification

An Elementor document is not finished when `_elementor_data` reads back
correctly. Elementor also serves generated element HTML, post CSS, atomic
styles, and the final browser layout. Stonewright treats those as separate
verification layers.

## Closed-loop workflow

For every Elementor document mutation in Plugin mode:

1. Call `stonewright-task-start` and load the matched Elementor skill/rules.
2. Read document health, structure hash, target elements, and the exact live
   widget/container schemas needed by the intended change.
3. Compile one reviewed patch list: `element_id`, setting, source evidence,
   desktop/tablet/mobile value, and expected rendered effect.
4. Prefer one dry-run and one consolidated
   `stonewright-elementor-v3-batch-mutate` call per post. Include
   `expected_tree_hash` where supported. Do not issue parallel writes to the
   same document.
5. Apply the typed write. Stonewright snapshots first, verifies serialized
   readback, then invalidates the target post's element cache, CSS state,
   WordPress object cache, and atomic style notification.
6. Call `stonewright-elementor-post-write-verify` with the touched element IDs
   and, only when needed, bounded content markers. It regenerates post CSS,
   warms Elementor through its public frontend renderer, and returns hashes and
   assertion receipts without returning the page HTML.
7. Use a browser to measure and capture the logged-out frontend at desktop,
   tablet, and mobile. Cache and HTML assertions are necessary, but they are not
   visual acceptance.

If any step fails, do not claim the page is live or verified. Repair the
specific layer, rerun the closure once, and restore the snapshot when the
document cannot be verified.

## Cache receipt

Post-scoped invalidation removes the document cache key exposed by Elementor's
`Document::CACHE_META_KEY` (falling back to `_elementor_element_cache` for
compatible versions), clears only that post's CSS state, cleans the WordPress
post cache, and emits Elementor's atomic-style clear notification. Stonewright
does not use a site-wide CSS clear as a fallback for a single document write.

`stonewright-elementor-post-write-verify` reports:

- whether element-cache and CSS invalidation closed successfully;
- the post-scoped CSS regeneration method;
- rendered byte count and SHA-256, never raw page HTML;
- pass/fail for requested Elementor element IDs;
- hashed pass/fail receipts for requested content markers;
- a browser recipe that explicitly keeps visual verification open.

The same invalidation runs after a successful Elementor snapshot restore, so a
rollback cannot leave the restored document behind stale generated HTML.

## Mixed Elementor V3/V4 documents

Do not rewrite a mixed document as one V3 tree. Read
`stonewright-elementor-document-health` and use its `v3_safe_roots`: each item
is a maximal V3-only subtree that can be targeted surgically. If the intended
target is a V4 atomic node or crosses a V3/V4 boundary, stop and use the
matching V4 ability or redesign the patch. Never strip unknown settings or
remap widget types to force validation.

## Schema evidence, not guessed controls

The live Elementor schema is authoritative. Before the write:

- request the exact responsive control keys and value domains;
- preserve activator controls required by dependent settings;
- use `_position` only when the live container schema exposes it;
- use device-specific hide values exposed by the current runtime;
- keep unknown settings unchanged.

A dry-run error can return `schema_requests`. Read each requested schema once,
replace only rejected settings, then rerun one consolidated dry-run. Repeated
guess-and-retry writes are a process defect.

## Measurement contract

Record source and live measurements in one compact table before applying
visual changes:

| Measurement | Source | Live target |
|---|---|---|
| section outer padding | Figma frame bounds and auto-layout | outer Elementor container |
| boxed content inset | Figma content frame | `.e-con-inner` and first semantic child |
| typography | Figma style/variable | live Elementor typography controls |
| carousel card, gap, peek | measured card bounds | rendered slide/card rectangles |
| responsive visibility | source breakpoint intent | computed `display` at each viewport |

For boxed containers, measure both the outer element and `.e-con-inner`; outer
padding alone can produce a false zero. For a full-bleed mobile carousel, keep
the carousel parent horizontal padding at zero and place text in its own inset
wrapper. Prefer one full mobile card plus a measured native offset; never set
two slides merely to create a peek.

The default verification viewports are the source desktop/mobile frames plus
the site's tablet breakpoint. A page is complete only when the requested
tolerances pass, there is no unintended horizontal overflow, visibility
matches the contract, and screenshots show the expected rendered state.

## Direct mode boundary

Local Direct mode can update Elementor data through tokenized WP-CLI. After a
verified write it removes the target post's element and CSS metadata and
attempts Elementor's CSS flush command. It still reports
`browser_required`; Direct mode has no typed live-schema validator.

Remote Direct REST cannot load Elementor's PHP renderer or post-cache manager.
It reports cache and frontend verification as `not_checked` instead of
claiming success. Use Plugin mode when a production Elementor change requires
typed schemas, mixed-tree safeguards, post-scoped frontend warming, or the
closed-loop verification ability.

## Why this matches Elementor

This contract follows Elementor's public document/frontend APIs and its
document-cache and atomic-style invalidation hooks. See Elementor's
[Element Caching update](https://developers.elementor.com/elementor-3-26-developers-update/),
[cache regeneration behavior](https://developers.elementor.com/elementor-3-28-developers-update/),
[multi-layer cache update](https://developers.elementor.com/elementor-3-32-developers-update/),
and [atomic styles clear hook](https://developers.elementor.com/docs/hooks/atomic-widgets-styles-clear/).
