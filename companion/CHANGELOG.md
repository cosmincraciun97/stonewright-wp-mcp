# Changelog

## [Unreleased]

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
