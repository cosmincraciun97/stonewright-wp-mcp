# Security Guarantees

This document states each hard rule from `AGENTS.md` explicitly, identifies the class or file that enforces it, and explains how to verify the guarantee via tests or static analysis.

---

## Rule 1 — No arbitrary PHP execution

**Statement:** `eval()`, `create_function()`, `assert()` with string arguments, and any dynamic dispatch that runs user-supplied PHP source are prohibited across the entire codebase and in any PHP file staged or activated through the Sandbox.

**What enforces it:**

- `plugin/includes/Security/StaticGuard.php` — tokenizes candidate PHP source with PHP's own `token_get_all()` and rejects any token matching the blocked-call list. Called by `SandboxWrite`, `SandboxEdit`, `SandboxActivate`, `WidgetDefine`, and `WidgetRegister` before any file is persisted or activated.
- `plugin/includes/Security/StaticAnalysis.php` — asserts at boot that the plugin's own source passes the same check.

**How to verify:**

```bash
cd plugin
grep -r "eval(" includes/ --include="*.php"  # zero hits expected
composer test -- --filter StaticGuard
```

Test files: `tests/Unit/SandboxStaticGuardTest.php`, `tests/Unit/StaticGuardReflectionBypassTest.php`.

---

## Rule 2 — No `__return_true` for writes

**Statement:** Every ability that writes, updates, or deletes state must use a real permission callback that calls into `Stonewright\WpMcp\Security\Permissions`. Read-only abilities may use simpler callbacks but must still pass through the Permissions helpers.

**What enforces it:**

- `plugin/includes/Security/Permissions.php` — the canonical permission helper. Every method wraps `current_user_can()`. Available methods: `read()`, `edit_posts()`, `edit_post()`, `upload_files()`, `edit_theme_options()`, `manage_options()`, `can_manage_sandbox()`, `can_view_sandbox()`, `can_manage_design()`, `can_view_design()`, `can_manage_fse()`, `can_edit_post()`, `can_create_post_type()`, `publish_cap_for_status()`, `can_edit_post_meta()`, `is_production_safe()`.
- `plugin/tests/Unit/AbilityKernelAuditTest.php` — iterates every class in `AbilityRegistry::list()`, instantiates it, and asserts that `permission_callback` returns `false` for unauthenticated callers.

**How to verify:**

```bash
cd plugin
grep -rn "__return_true" includes/Abilities/ --include="*.php"  # zero hits expected
composer test -- --filter AbilityKernelAudit
```

---

## Rule 3 — Backup before write

**Statement:** Before mutating an Elementor post, a global styles record, a template, or any theme.json-backed content, `Stonewright\WpMcp\Security\Backup::snapshot_post( $post_id )` must be called.

**What enforces it:**

- `plugin/includes/Security/Backup.php` — serializes `post_title`, `post_status`, `post_content`, `post_excerpt`, and all `_stonewright_*` meta into a JSON snapshot stored in `_stonewright_backups` post meta. Returns a `snapshot_id` UUID.
- 32 abilities call `Backup::snapshot_post()` before the first write. The ability truth matrix (`docs/ability-truth-matrix.md`) lists the Backup column for each ability.

**How to verify:**

```bash
cd plugin
grep -rn "Backup::snapshot_post" includes/Abilities/ --include="*.php"  # 30+ hits
composer test -- --filter FseWriteSafety
composer test -- --filter ElementorWriter
```

Test files: `tests/Unit/FseWriteSafetyTest.php`, `tests/Integration/ElementorWriterTest.php`.

---

## Rule 4 — Validator before render

**Statement:** Before handing a design spec to any renderer, `Stonewright\WpMcp\DesignSpec\Validator::validate( $spec )` must be called. Invalid specs are rejected with a `WP_Error` whose code is `stonewright_spec_invalid`.

**What enforces it:**

- `plugin/includes/DesignSpec/Validator.php` — validates against the bundled JSON Schema (`plugin/schemas/design-spec.v1.schema.json`) using `opis/json-schema`. Returns the normalized spec or a `WP_Error`.
- `plugin/includes/ThemeJson/Validator.php` — equivalent validator for theme.json payloads, used by `FSE\WriteGlobalStyles`.
- 12 abilities call `Validator::validate()` before any render or write path.

**How to verify:**

```bash
cd plugin
composer test -- --filter ValidatorTest
composer test -- --filter RendererValidation
composer test -- --filter ThemeJsonValidator
```

Test files: `tests/Unit/ValidatorTest.php`, `tests/Unit/ThemeJsonValidatorTest.php`, `tests/Unit/RendererValidationTest.php`.

---

## Rule 5 — Confirmation tokens for destructive operations

**Statement:** When `get_option( 'stonewright_mode', 'development' ) === 'production-safe'`, every destructive ability must verify a token via `ConfirmationToken::verify( $token, $ability_name, $args )`. Tokens are issued by `stonewright/security-issue-confirmation-token` and are short-lived.

**What enforces it:**

- `plugin/includes/Security/ConfirmationToken.php` — issues HMAC-signed tokens with a configurable TTL (default 120 seconds) and verifies them, rejecting tokens that are expired, mismatched on ability name/args, or already consumed.
- `plugin/includes/Abilities/Common/ConfirmationGuard.php` — a trait providing `require_confirmation( array $args )`. Abilities using this trait call it at the top of `execute()`.
- 25 abilities are token-gated (see Ability Truth Matrix). Sandbox abilities use `SandboxGuards::require_sandbox_confirmation()` which calls `ConfirmationToken::verify_or_error()` directly.

**How to verify:**

```bash
cd plugin
composer test -- --filter ConfirmationToken
composer test -- --filter AbilityConfirmation
composer test -- --filter SandboxLibraryProductionSafe
```

Test files: `tests/Unit/ConfirmationTokenTest.php`, `tests/Unit/ConfirmationTokenSchemaTest.php`, `tests/Integration/AbilityConfirmationTest.php`, `tests/Unit/SandboxLibraryProductionSafeTest.php`.

---

## Rule 6 — Production-safe mode

**Statement:** The plugin must always honor the three modes `development`, `staging`, and `production-safe`. The admin UI exposes the toggle. The Permissions and ability gates read the option.

**What enforces it:**

- `plugin/includes/Security/Permissions.php` — `is_production_safe()` returns `true` when `stonewright_mode === 'production-safe'`.
- Confirmation guard checks call `is_production_safe()` before requiring a token. In `development` or `staging` mode, destructive abilities run without a token.
- `plugin/includes/Admin/SettingsSanitizer.php` — validates that only the three allowed mode strings can be stored.

**How to verify:**

```bash
cd plugin
composer test -- --filter PermissionsTest
composer test -- --filter SettingsSanitizer
```

---

## Rule 7 — Companion never writes to WordPress

**Statement:** The Node companion handles Figma ingestion, Playwright screenshots, pixel diff, and an optional MCP HTTP proxy. It must not call WordPress REST write endpoints or shell into WP-CLI.

**What enforces it:**

- By design: companion endpoints are `/health` (GET), `/screenshot` (POST), `/diff` (POST), `/axe` (POST), `/layout` (POST), `/lighthouse` (POST). None of these paths make HTTP requests to WordPress.
- `companion/src/http-api.ts` does not import any WordPress client library and has no code paths that call WP REST API write routes.
- The PHP `CompanionClient` is the only caller of companion endpoints and only reads responses.

**How to verify:**

```bash
cd companion
grep -rn "wp-json" src/ --include="*.ts"   # zero write-endpoint hits expected
npm test
```

---

## Threat model

### In scope

The security envelope (rules above) is designed to protect against:

- **Unintended writes** — a malicious or misconfigured MCP prompt causing an agent to overwrite production post content, theme settings, or FSE templates without explicit operator approval.
- **Privilege escalation within WordPress** — an ability granting a lower-privileged user access to higher-capability operations.
- **Sandbox code injection** — a sandboxed widget or plugin file containing `eval()`, shell commands, or dynamic dispatch patterns.
- **SSRF via companion** — the companion validates all incoming URLs against `^https?://` before passing them to Playwright. `file://`, `data:`, and relative URLs are rejected.
- **Path traversal in sandbox** — `SandboxGuards` validates `artifact_path` and `file_name` against strict allow-list regexes before any filesystem operation.
- **Token replay** — confirmation tokens are single-use and expire after TTL. The token payload includes the ability name and argument hash.

### Out of scope

- **WordPress core CVEs** — Stonewright relies on a correctly patched WordPress installation. Core vulnerabilities are the responsibility of the site operator.
- **Supply-chain attacks** — Stonewright's composer and npm dependency trees are not audited as part of this security model.
- **Network-level attacks** — TLS termination, rate limiting, and IP allowlisting are infrastructure concerns outside the plugin boundary.
- **Elementor core vulnerabilities** — Stonewright validates its own writes into Elementor data structures but cannot prevent vulnerabilities in Elementor's own rendering layer.

---

## Quick verification matrix

| Guarantee | Grep | Tests |
|---|---|---|
| No arbitrary execution | `grep -r "eval(" includes/` | `SandboxStaticGuardTest` |
| No __return_true | `grep -rn "__return_true" includes/Abilities/` | `AbilityKernelAuditTest` |
| Backup coverage | `grep -rn "Backup::snapshot_post" includes/Abilities/` | `FseWriteSafetyTest`, `ElementorWriterTest` |
| Validator coverage | `grep -rn "Validator::validate" includes/Abilities/` | `ValidatorTest`, `RendererValidationTest` |
| Token gates | `grep -rn "ConfirmationGuard" includes/Abilities/` | `ConfirmationTokenTest`, `AbilityConfirmationTest` |
| Mode awareness | `grep -rn "is_production_safe" includes/` | `PermissionsTest`, `SandboxLibraryProductionSafeTest` |
| Companion isolation | `grep -rn "wp-json" companion/src/` | `companion: npm test` |
