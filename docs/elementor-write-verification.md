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
- `cache_purge`: the page cache purge that follows a regeneration which wrote
  the file, did not roll back and moved the version (see "Page cache purge"
  below). `ran` lists the purger ids that were called, `failed` the ones that
  threw (`purger` and `error_class`, never the message), `skipped` a purger that
  was present but not called, and `skipped_reason` says why no page cache purger
  ran: `no_page_cache_plugin`, `disabled_by_filter`, `css_not_regenerated` or
  `css_version_not_changed`;
- HTTP probes for the target CSS and any existing
  `custom-frontend.min.css` / `custom-pro-widget-nav-menu.min.css` assets.
  Probes are anonymous and never go to another origin than the site. A redirect
  chain of at most two same-origin hops (same scheme, host and port, never a
  login page) that ends in HTTP 200 `text/css` with the written bytes is
  `verified`, and the probe records `redirect_hops`. A cross-origin, downgraded
  or looping redirect fails with `stonewright_elementor_css_probe_unsafe_redirect`
  before any write, and the error says which kind it was and what to change.
- Once the file is written and verified on disk, what the anonymous probe finds
  never turns the call into a failure. The call answers `ok: true`, with a
  `delivery_cause` on the result and on each probe row, a `warnings` entry that
  names the cause in plain words, and a `repair` text with the next step. The
  layout must not be rebuilt because of it:

  | `delivery_cause` | Found | `delivery_status` | Warning code |
  |---|---|---|---|
  | `login_redirect`, `login_page` | A redirect to the login page, or a login page answered in place of the file | `blocked` | `stonewright_elementor_css_delivery_protected` |
  | `http_401`, `http_403` | Authentication or a firewall refuses the file | `blocked` | `stonewright_elementor_css_delivery_protected` |
  | `other_page_html`, `redirect_to_other_page` | An HTML page (maintenance, coming soon, a security challenge) or a redirect to a page that is not the stylesheet | `blocked` | `stonewright_elementor_css_delivery_protected` |
  | `http_5xx`, `no_response` | HTTP 5xx (for example a maintenance mode answering 503), a timeout or no connection | `not_checked` | `stonewright_elementor_css_delivery_unverified` |
  | `http_404`, `http_<4xx>`, `not_css` | Another 4xx, or an answer that is not CSS, on a URL that was not delivered before the write | `failed` | `stonewright_elementor_css_delivery_unverified` |
  | `other_origin_not_probed` | The uploads URL is on another origin (a CDN or an offload plugin); it is never requested | `not_checked` | `stonewright_elementor_css_delivery_unverified` |

  A file that was delivered before the write and answers with a 404, a non-CSS
  body or a redirect to another page after it fails with
  `stonewright_elementor_css_probe_failed` and is rolled back. A login page,
  401, 403, 5xx, no answer and another HTML page after the write are changes of
  the environment, not evidence against the written file, and keep it;
- collateral-change and rollback status;
- backup snapshot id and `effect_verified` only when the requested effect
  actually closed.

### Page cache purge

After a regeneration that wrote the CSS file without a rollback and moved the
stylesheet version, `stonewright-elementor-css-regenerate` purges the cached
HTML of that one post. A purger is used only when its plugin exposes the call
(a hook with a listener, a public function or the plugin's own hooks object);
nothing else is called, and the whole-site purge of any plugin is never used.

| Id | Plugin | Call |
|---|---|---|
| `litespeed_cache` | LiteSpeed Cache | `do_action( 'litespeed_purge_post', $post_id )`, when the action has a listener |
| `wp_rocket` | WP Rocket | `rocket_clean_post( $post_id )` |
| `w3_total_cache` | W3 Total Cache | `w3tc_flush_post( $post_id )` |
| `wp_super_cache` | WP Super Cache | `wp_cache_post_change( $post_id )` |
| `wp_fastest_cache` | WP Fastest Cache | `wpfc_clear_post_cache_by_id( $post_id )` |
| `siteground_optimizer` | SiteGround Optimizer | `sg_cachepress_purge_cache( $post_url )`, only for a post URL with a path and no query string; skipped (`skipped.siteground_optimizer`) for the site root, a plain-permalink URL or a URL on another host, because that function purges the whole site for those |
| `cloudflare` | Cloudflare | `purgeCacheByRelevantURLs( $post_id )` on the plugin's `$cloudflareHooks` object, which the plugin only acts on when its own cache or Automatic Platform Optimization is enabled |
| `wordpress_post_cache` | WordPress core | `clean_post_cache( $post_id )`, last, which also reaches plugins that listen to that action |

`ran` means the call was made, not that the cache held an entry for the page.
Each plugin decides what a post purge covers; several also purge related pages
such as the home page and the archives of the post, and SiteGround also purges
the paths below the post URL. A page that only uses a loop or
theme template is not purged individually, and a host, CDN or proxy cache that
exposes none of these calls is not reached.

The `stonewright_css_regenerate_purge` filter receives the array of purgers that
are present (`id => callable( int $post_id, string $post_url )`), the post id and
the post URL, and returns the array to use. Returning `false` or an empty array
turns the purge off (`skipped_reason: disabled_by_filter`); an entry added to
the array runs as a site purger and is reported under its key; an entry that is
not callable is dropped. A purger or filter that throws is reported in
`cache_purge.failed` and as a `stonewright_css_cache_purge_failed` warning with
the plugin name and the exception class, and the write still counts.

When a regeneration answers with a `repair` text, it first says which page
caches Stonewright already purged and only then asks for a manual purge of any
other host, CDN or page cache.

`stonewright-elementor-post-write-verify` reports:

- rendered byte count and SHA-256, never raw page HTML;
- pass/fail for requested Elementor element IDs, found by the markers the
  element's kind renders: a V3 element or a V4 container (`e-div-block`,
  `e-flexbox`, `e-grid`, the accordion and list families) by `data-id` or the
  `elementor-element-<id>` class; V4 tabs, background video and form elements by
  `data-id`; V4 leaf widgets (`e-heading`, `e-paragraph`, `e-button`,
  `e-image`, `e-svg`, `e-divider`) by `data-interaction-id`; `e-youtube` and
  `e-self-hosted-video` by `data-id` or `data-interaction-id`. An id matches as a
  whole token, so `abc` is not found in `abcd`. Each check lists the
  `markers_checked` and the `matched_marker`;
- for every element that is not found a `reason`, decided from evidence:
  `not_in_document` (the id is not in the stored tree), `not_rendered` (the
  document rendered and the element did not; an `e-*` element also gets a note
  when the atomic-elements experiment is off), `render_empty`,
  `password_protected`, `not_built_with_elementor`, `no_elementor_data`,
  `cached_html_suspected` (the stored tree has the element, the render lacks it
  and Elementor's element cache holds an unexpired entry for the post) or
  `renderer_failed` (the exception class only). `missing_elements` lists them,
  and `render_context` gives the evidence: `post_status`, `password_protected`,
  `built_with_elementor`, `element_cache_active`, `rendered_bytes` and
  `atomic_elements_active`. A post that is not published is still verified
  (Elementor renders drafts) and `notes` says an anonymous visitor cannot see it;
- on a failure a plain `message` that names up to five missing ids with their
  reasons and the next step, so the Audit Log row carries it;
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

## Write pacing

A run of small saves of one document is the costliest way to edit on a busy site:
each save rebuilds the element cache and can empty the page cache. Stonewright counts
the saves of each Elementor document in a ten-minute window, whoever makes them
(a Stonewright writer or a person in the editor), and reports the count to the agent.

- The response of the request that made the third save of a post in the window
  carries `write_pacing`: the posts, the number of saves each had, the window in
  minutes and the hint to consolidate the remaining changes into one
  `stonewright-elementor-v3-batch-mutate` apply.
- Under **Stonewright > Setup > Settings > Busy sites**, **Elementor write pacing**
  can be set to strict. The sixth save of one post in the window is then refused with
  `stonewright_write_pacing` (status 429, with `retry_after` in seconds and the same
  hint) until the window has passed. The default is warn, which never refuses; off
  counts nothing. Only a person in wp-admin makes the setting looser.
- The editor's autosaves and revisions are not saves of the post and are not counted.
- The count lives in the transient `stonewright_elementor_saves_<post id>`, which
  expires with the window. A write that is refused is not counted, and a rollback is
  never paced.

See [Stonewright on busy or constrained sites](busy-sites.md).

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

A refused batch returns `rejected_settings`: for each rejected setting the
operation, the element or parent, the widget type, the setting, what was
received, what the live control accepts (`allowed_values`, `units`,
`responsive_keys`, `requires`) and an example. Replace those values, then rerun
one consolidated dry-run. A dry-run error can also return `schema_requests`; read
each requested schema once, for a setting that has no example. Repeated
guess-and-retry writes are a process defect. See
[Elementor V3 schema repository](elementor-v3-schema-repository.md#when-a-setting-is-refused).

## Troubleshooting a failed call

Every failed call carries a message that says what failed, and the Audit Log row
keeps it. What to read, and what to send next:

| Code | Read | Next call |
|---|---|---|
| `stonewright_batch_operation_failed` with cause `stonewright_elementor_settings_invalid` | `rejected_settings`: operation, element or parent, widget type, setting, received value, `allowed_values`, `units`, `requires`, `example` | The same batch with only the rejected values replaced, as a dry run first |
| `stonewright_batch_operation_failed` with another cause | `items[].error` and the message (`Operation 0 failed: ...`); a breakpoint refusal says to add the breakpoint to `responsive_scope` | The batch with that operation corrected |
| `stonewright_elementor_frontend_verification_failed` | `missing_elements` (element id, widget type, `reason`), `render_context`, `message` | The step the reason names: clear the Elementor cache for `cached_html_suspected`, verify in a logged-in browser for `password_protected`, re-read the tree for `not_in_document` |
| `stonewright_elementor_css_delivery_protected`, `stonewright_elementor_css_delivery_unverified` (warnings of an `ok: true` result) | `delivery_cause`, the warning `message` and `repair` | Nothing in Elementor: check the stylesheet URL in a logged-out browser and the host, firewall, login or CDN rule in front of it |
| `stonewright_elementor_css_probe_failed` | `rollback_status: succeeded` | Make the stylesheet URL answer 200 `text/css` to a logged-out request, then regenerate once |
| `stonewright_not_found` from `section-reuse-extract` | `reason` and, for a user who may edit the post, `offered_statuses` | A `post_id` from `section-reuse-find` |
| `stonewright_section_not_found` from `section-reuse-extract` | `valid_locators`, `closest` | One of the listed locators |
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
successful write it removes only the target post's element/HTML cache. It
preserves CSS metadata and never calls Elementor's global CSS flush command.
The result reports `css_safety_status=preserved_pending_plugin_verification`
and remains `browser_required`; use Plugin mode for guarded target-post CSS
closure via `stonewright-elementor-css-regenerate` then
observation-only `stonewright-elementor-post-write-verify`. Direct mode has no
typed live-schema validator and must not pass `regenerate_css` to verify;
that input does not exist.

Direct `elementor-data-update` refuses custom CSS setting keys and HTML-type
widgets, refuses duplicate element ids, runs an integrity gate, and saves a
full-tree file backup that `stonewright-direct-backup-restore` puts back. A base
hash is optional: pass the hash from `elementor-data-get` as `expected_hash` and
the write is refused when the stored tree changed since. There is no per-post
lock or lease, so without `expected_hash` a concurrent edit is overwritten. After
the write the tool reads `_elementor_data` back and reports `verification` as
`verified`, `mismatch` or `not_checked`; a mismatch is reported, not restored
automatically. It uses WP-CLI only when the local install's `home` or `siteurl`
matches the alias URL, and otherwise reports `wp_cli_refused` and uses REST.

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
