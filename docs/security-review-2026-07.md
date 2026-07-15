# Security review — 2026-07 (Pillar G)

Scope: Stonewright plugin admin surface, abilities, asset sideload, sandbox paths, audit redaction, php-execute, companion WP-CLI.

| Area | Finding | Severity | Status |
|---|---|---|---|
| Admin POST/AJAX | Theme AJAX uses nonce + `manage_options`; settings via `register_setting`; Memory/Sandbox handlers use nonces + caps | — | Verified OK |
| Write abilities | No `__return_true` on write callbacks; `Permissions::*` used | — | Verified OK |
| Confirmation tokens | production-safe mode gates destructive abilities | — | Verified OK |
| HTML widgets | Soft flag only → **hard site option** `stonewright_allow_html_widgets` default OFF; per-call flag ignored when off | High | **Fixed** `HtmlWidgetPolicy` |
| Asset sideload | HTTP(S) only; size/type limits exist in AssetSideloader | Medium | Verified OK (re-test private IPs if extended) |
| Sandbox paths | Path guards in Sandbox library | Medium | Verified OK |
| Audit secrets | Review recommended recursive mask for token/password keys | Medium | Documented — follow-up if gaps |
| php-execute | Mode gate + permissions + audit; no Elementor tree writes | — | Verified OK |
| Companion WP-CLI | `execFile` argv tokens only | — | Verified OK |
| Companion HTTP | Bearer + origin guards when enabled | — | Verified OK |

## Fixes in this round

1. **HTML widget hard-block** — `plugin/includes/Elementor/HtmlWidgetPolicy.php` wired into AddWidget, BatchMutate, WidgetAbilityBase; Settings toggle; agent instructions updated.
2. **Essential surface** — blueprint/digest/pulse/learning exposed so agents stop improvising via php-execute.

## Residual risk

- Direct mode still authenticates with Application Passwords over HTTP when operator chooses HTTP (by design for local). Prefer HTTPS off-LAN.
- Audit payload redaction: re-audit recursive key masking if new secret field names appear.
