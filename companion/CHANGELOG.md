# Changelog

## [Unreleased]

### Added

- Add `stonewright connect add/list/use/verify/repair/remove/migrate` with a
  secret-free schema-v2 site registry, OS credential stores, unique aliases,
  client adapters, and spawned-runtime verification.
- Store per-site mode/Step 1 expectations and per-client browser provider,
  scan-consent, and install-consent state without silently scanning or installing.
- Add the short `stonewright` CLI binary while retaining the existing
  `stonewright-companion` and `stonewright-mcp` entry points.

### Changed

- Make site, credential, and client-config changes transactional: duplicate
  endpoints fail before secret writes and registry failures restore the exact
  client config and credential state.

### Fixed

- Make task-start profile expansion register callable tools before notifying
  clients, and make repeated Plugin/Direct reconnects reuse existing tool and
  prompt handles instead of failing on duplicate registration.
- Report authentication as configured only when complete credential evidence
  exists and leave unprobed Direct WordPress reachability unknown.

## [1.0.0-beta.4] - 2026-08-05

### Added

- Add atomic OAuth token storage, refresh-token rotation, single-flight
  refreshes, terminal reauthorization handling, bounded transient retries,
  `Retry-After`, jitter, and circuit breaking.

### Fixed

- Treat a successful refresh response that omits a new token or replays the
  previous refresh token as terminal reauthorization instead of reusing a
  credential the server has already rotated.
- Send the canonical MCP `resource` during token refreshes, route OAuth traffic
  through the dedicated resource endpoint, and update vulnerable transitive
  dependencies to patched releases.

## [1.0.0-beta.3] - 2026-07-31

### Changed

- Clarify that local stdio starts the companion on the user's computer, while
  Remote Streamable HTTP connects directly to the plugin.
- Keep Direct mode custom CSS readable where core exposes it, but block writes
  because pluginless mode has no authenticated wp-admin one-time-grant
  boundary.
- Mirror the human custom-code handoff rule from Plugin mode.
- Mirror the immutable Elementor write-closure rule and align the companion
  package with the beta.3 plugin release.

### Fixed

- Local WP-CLI Elementor writes now remove the target post's element and CSS
  cache metadata after verified readback and report browser verification as
  still required.
- Remote Direct writes report Elementor PHP cache/frontend checks as
  `not_checked` instead of claiming closure they cannot perform.

## [1.0.0-beta.2] - 2026-07-30

### Changed

- Align the companion package version with the beta.2 plugin release. Direct
  mode behavior, private state, rules, and update-preservation guarantees remain
  unchanged.

## [1.0.0-beta.1] - 2026-07-30

### Changed

- Add plugin-mode profile routing for all native WooCommerce catalog abilities.
- Keep Direct mode WooCommerce read-only and state that boundary in the
  capability matrix.
- Align package version and public-hygiene release gates with the first beta.
- Keep the shared native-rule registry and digest-based reads on the Direct
  bootstrap surface.
- Accept canonical `appPassword` Direct config while preserving the legacy key.
- Write `~/.stonewright` state with restrictive permissions and emit a
  credential-free MCP config from `stonewright-companion init`.
- Make `stonewright-task-start` the first Direct call, emit mode-aware
  handshake guidance, and stop reporting Direct memory as plugin-only.
- Replace supplied passwords and authorization values with private
  placeholders in setup-profile output.
- Redact credential material from Direct audit messages and reject secrets in
  locally persisted memory and user-created skills.
- Preserve Direct memory, user skills, and audit state across restarts and
  companion updates; fresh state starts without user records.
- Mirror the expanded immutable Plugin rule registry in Direct mode so
  site-independent Elementor, content-model, asset, query, custom-code,
  architecture, and rendered-proof guidance never depends on site memory.
