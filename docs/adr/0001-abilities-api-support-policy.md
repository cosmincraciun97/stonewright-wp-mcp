# ADR-0001: Abilities API support policy

**Status:** Accepted  
**Date:** 2026-07-16  
**Deciders:** Stonewright maintainers  

## Context

Stonewright registers abilities through the WordPress Abilities API. Two
implementations exist in the wild:

1. **WordPress core 6.9+** ships Abilities API under `wp-includes/abilities-api/`
   and fires `wp_abilities_api_categories_init` + `wp_abilities_api_init`.
2. **Composer package `wordpress/abilities-api` (≤ 0.1.0)** is vendored for
   pre-6.9 cores. It fires the un-prefixed `abilities_api_init` action and has
   no separate categories init.

The upstream Composer package is effectively abandoned for ongoing core
evolution; core is the long-term home of the API. Stonewright still supports
WordPress **6.7–6.8** (plugin header `Requires at least: 6.7`), so the package
remains a deliberate pin rather than accidental drift.

## Decision

1. **Keep** the Composer dependency `"wordpress/abilities-api": "^0.1.0 || ^1.0"`
   while the minimum supported WordPress version is below 6.9.
2. **Load via feature detection, not version sniffing.** The package bootstrap
   (`includes/bootstrap.php`) only defines `WP_Ability` / registry / procedural
   helpers when `class_exists( 'WP_Ability' )` (and related guards) report them
   absent. When core already defined the API, the vendor copy is a no-op.
3. **Register on every init action any supported flavour may fire**
   (`wp_abilities_api_categories_init`, `wp_abilities_api_init`,
   `abilities_api_init`). `AbilityRegistry::register_all()` stays idempotent.
4. **Removal trigger:** drop the Composer package when Stonewright’s minimum
   supported WordPress version is **≥ 6.9**. Document that change in the
   release notes and this ADR’s status (superseded).

## Consequences

- Fresh installs on WP 6.9+ pay a tiny autoload cost for a bootstrap that
  immediately exits the class load path; no dual registries.
- Pre-6.9 installs keep full ability registration without requiring a core
  upgrade solely for MCP.
- CI and contract tests continue to exercise the dual-hook registration path
  (`AbilitiesApiHookNameTest`, `AbilitiesApiCompatLoadTest`).
- Upstream abandonment is accepted and owned: we pin deliberately and re-evaluate
  only when the support floor moves.

## Related

- `plugin/composer.json` dependency
- `plugin/vendor/wordpress/abilities-api/includes/bootstrap.php` feature guards
- `plugin/includes/Core/PluginRegistration.php` dual-hook registration
- `plugin/tests/Unit/AbilitiesApiHookNameTest.php`
- `plugin/tests/Unit/AbilitiesApiCompatLoadTest.php`
