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
   same document. In `production-safe` mode the apply needs a confirmation token
   issued for exactly that call and the dry run needs none. When another writer holds
   the page, the call returns the retryable `stonewright_elementor_write_busy` with
   `retry_after` seconds: wait, then repeat that one call.
5. Apply the typed write. Stonewright snapshots first, verifies serialized
   readback, then invalidates only the target post's element/HTML cache and
   WordPress object cache. It does not delete CSS metadata or clear Elementor's
   site-wide files manager.
6. Call `stonewright-elementor-css-regenerate` when the write affects generated
   CSS. It snapshots the post, inventories the direct CSS directory, probes
   existing protected URLs, regenerates only the resolved post or loop target
   through Elementor's official `update()` API (the file and the stored CSS
   metadata), and restores its bounded asset snapshot if another file changes
   or a probe fails. Restore runs only
   while the CSS directory lease still identifies this writer, including an
   expired-but-ours lease. A vacant lease after another writer committed and
   released is a successor fence: skip restore (`not_attempted_lock_lost` /
   `stonewright_elementor_css_lease_lost`) and do not reclaim the empty slot. A
   different owner, live or expired, is also a skip
   (`stonewright_elementor_css_lease_busy`). After a successful CSS transaction,
   a later post-lock renew failure is non-fatal
   (`lock.renew_after_commit=lost_after_commit`): the write is already closed,
   release is best-effort, and rolling CSS or the document back would desync a
   committed pair. Before CSS starts, post-lock renew retries a same-owner
   WordPress options CAS miss and continues while this writer still owns a live
   lease. During CSS closure, CSS-directory-lease renew does the same: a
   same-owner options CAS miss is retried, and a live owned lease is kept.
   `stonewright_elementor_lock_lost` / `stonewright_elementor_css_lease_lost`
   mean the lease is gone, expired, or foreign — not a serialization
   false-negative.
7. Call `stonewright-elementor-post-write-verify` with the touched element IDs
   and, only when needed, bounded content markers. Never pass `regenerate_css`;
   that unsafe switch does not exist. The verifier is observation-only: it
   renders through Elementor with CSS generation disabled and returns bounded
   assertions. It does not regenerate CSS, invalidate caches, or roll back files.
8. Use a browser to measure and capture the logged-out frontend at desktop,
   tablet, and mobile. Cache and HTML assertions are necessary, but they are not
   visual acceptance.

If any step fails, do not claim the page is live or verified. Repair the
specific layer, rerun the closure once, and restore the snapshot when the
document cannot be verified.

Every typed Elementor batch returns one bounded write receipt containing the
transaction/change-set IDs, architecture, target IDs, lock and snapshot
status, before/planned/after hashes, verification and rollback status, root
error path, retry guidance, recovery tool, warnings, and audit event ID. The
orchestrator owns rollback; low-level data access returns partial state instead
of restoring a second time.

## Cache receipt

Post-scoped invalidation removes the document cache key exposed by Elementor's
`Document::CACHE_META_KEY` (falling back to `_elementor_element_cache` for
compatible versions) and cleans the WordPress post cache. It preserves
`_elementor_css`, never calls Elementor's global files-manager clear, and never
emits a site-wide atomic-style clear for one post.

`stonewright-elementor-css-regenerate` first scans the stored settings of the
post, and its page or kit settings, for CSS control characters under colour,
typography, unit and numeric-side keys. A match fails with
`stonewright_elementor_css_unsafe_value` and the paths, before any backup, lock
or generation.

`stonewright-elementor-css-regenerate` reports:

- target kind and filename (never raw path or URL);
- hashed path/URL and before/after direct-file manifest hashes;
- distinct `generation_status`, `delivery_status`, and
  `frontend_verification_status` (`verified|blocked|failed|not_checked`;
  `delivery_status` also reports `not_applicable` when the page has no CSS
  file);
- `css_file_status` (`present|not_produced`): a page whose styles are empty,
  for example a page built only from Atomic elements that keep their styles in
  the markup, produces no post CSS file. The call then succeeds with
  `css_file_status: not_produced` and `css_file_reason: empty_css`, only when
  Elementor itself reports an empty stylesheet and no other CSS asset changed.
  A missing file without that evidence, or under the internal CSS print method
  (`stonewright_elementor_css_inline_print_method`), is still refused and
  rolled back;
- `css_version` and `css_version_before`, the `?ver=` of the page's
  stylesheet link before and after, and `css_version_changed`. A written file
  always moves the version forward, whatever the anonymous probe answers, so
  browsers and page caches fetch the new file;
- HTTP probes for the target CSS and any existing
  `custom-frontend.min.css` / `custom-pro-widget-nav-menu.min.css` assets.
  Probes are anonymous and same-origin. A redirect chain of at most two
  same-origin hops (same scheme, host and port, never a login page) that ends
  in HTTP 200 `text/css` with the written bytes is `verified`, and the probe
  records `redirect_hops`. A cross-origin, downgraded or looping redirect fails
  with `stonewright_elementor_css_probe_unsafe_redirect` before any write. A
  redirect to a login page or to any page that is not the CSS, a 401 or a 403,
  is access control in front of the file: `delivery_status` is `blocked`, the
  call answers `ok: true` with `warnings`
  (`stonewright_elementor_css_delivery_protected`) and a `repair` text, and the
  layout must not be rebuilt because of it. A file that was delivered before the
  write and is not after it fails with `stonewright_elementor_css_probe_failed`
  and is rolled back;
- collateral-change and rollback status;
- backup snapshot id and `effect_verified` only when the requested effect
  actually closed.

`stonewright-elementor-post-write-verify` reports:

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

## Stale editor save

An Elementor editor session captures the server `_elementor_data` hash and
`post_status` at `elementor/editor/init`. Persist through
`elementor/document/before_save` re-reads that server copy. If MCP or another
writer changed the document after the editor loaded, save is blocked with
`stonewright_elementor_stale_editor`, the local editor draft is not discarded,
and `post_status` stays unchanged. Visual `save` and the native editor button
share that PHP gate. `expected_tree_hash` on
`stonewright-elementor-v3-batch-mutate` is a different path: it protects a stale
MCP plan, not an open editor.

## Schema evidence, not guessed controls

The live Elementor schema is authoritative. Before the write:

- request the exact responsive control keys and value domains;
- preserve activator controls required by dependent settings;
- use `_position` only when the live container schema exposes it;
- for the primary Elementor visibility switches, use only the matching native
  values: `hide_desktop=hidden-desktop`, `hide_laptop=hidden-laptop`,
  `hide_tablet=hidden-tablet`, and `hide_mobile=hidden-mobile`; never use bare
  `hidden`;
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
The Elementor editor canvas can still display a widget hidden for the frontend;
that is expected. Read back the saved switcher value and verify the rendered
frontend class and computed display at each requested device.

## Direct mode boundary

Local Direct mode can update Elementor data through tokenized WP-CLI. After a
verified write it removes only the target post's element/HTML cache. It
preserves CSS metadata and never calls Elementor's global CSS flush command.
The result reports `css_safety_status=preserved_pending_plugin_verification`
and remains `browser_required`; use Plugin mode for guarded target-post CSS
closure via `stonewright-elementor-css-regenerate` then
observation-only `stonewright-elementor-post-write-verify`. Direct mode has no
typed live-schema validator and must not pass `regenerate_css` to verify;
that input does not exist.

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
