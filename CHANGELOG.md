# Changelog

All notable changes to Stonewright are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and the project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public changelog starts with the first beta. Pre-beta development builds
were never stable releases and are not part of the supported public history.

## [Unreleased]

## [1.0.0-beta.2] - 2026-07-30

### Added

- A maintained `DESIGN.md` system and page-by-page admin surface checklist for
  the supported light interface.

### Changed

- The Dashboard uses one compact summary band and balanced evidence panels
  instead of a wall of oversized metric cards.
- Setup, Sandbox, Audit, Abilities, Design Studio, Visual Workspace, Blueprints,
  Memory, and Skills now share tighter typography, spacing, focus, status,
  border, and contrast contracts.

### Fixed

- Make an explicit **Check latest companion** action bypass the 12-hour release
  cache and browser caches, while retaining the cache for automatic background
  checks.
- Preserve every top-level field required by an ability output schema when
  `stonewright_fields` projects a smaller response. Compact `task-start`
  responses can no longer lose required handshake fields.
- Center the complete Domain Lock control group, keep Sandbox file-type badges
  readable, remove inline click handling from category actions, and correct
  low-contrast setup code blocks and focus rings.

## [1.0.0-beta.1] - 2026-07-30

### Added

- Native WooCommerce catalog abilities for status, product and variation
  reads/writes, catalog terms, global attributes, safe deletes, and bounded
  catalog audits. Plugin mode now exposes 346 abilities; Direct mode remains
  read-only for WooCommerce.
- Runtime discovery for common builders, themes, block libraries, forms, field
  plugins, add-ons, dynamic-data plugins, code tools, and SEO suites. Detected
  integrations without typed adapters are reported as discovery-only.
- Public-repository hygiene checks for private project identifiers in source,
  staged release archives, and optionally commit history.
- A credential-free paste-to-agent prompt, Plugin/Direct badges in the Prompt
  Library, and an in-product update guide for the plugin and companion.
- A release-aware companion check in Setup with trusted package/checksum links
  and a credential-free update prompt for the AI client.
- Persistent-data lifecycle contracts proving that fresh installs start with
  no user memory, user-created skills, or audit events.

### Changed

- WordPress release archives now come from a clean production Composer install.
  The Jetpack Autoloader is a production dependency, its package loader is the
  primary bootstrap, and every manifest path is verified before publication.
- WooCommerce catalog writes use native WooCommerce objects and allowlisted
  setters, preview by default, enforce permissions and production
  confirmations, record audit entries, and verify readback.
- Storefront guidance routes Elementor through live Woo widget schemas and
  Gutenberg/FSE through registered `woocommerce/*` block schemas.
- Direct mode remains first-class: it accepts the canonical `appPassword`
  sites key plus the legacy key, writes private state with restrictive file
  permissions, prints a secret-free client configuration, starts with
  `stonewright-task-start`, and no longer labels local memory as plugin-only.
- Setup diagnostics never return a supplied password or authorization value;
  copyable environment blocks use private placeholders.
- Plugin and Direct memory/skill writes reject high-confidence credential
  material. Direct audit errors redact authorization headers, tokens, and
  Application Passwords before persistence.
- Updates preserve existing plugin memory, user skills, audit history, admin
  settings, and Direct state under `~/.stonewright/`.
- The native rule registry, bounded memory generalization, response field
  projection, Elementor `knownHash` reads, and conservative escaped-layout
  decoding are part of the first supported beta baseline.
- Eight site-independent operating rules now cover Elementor transaction
  discipline, responsive semantic widgets, verified content models, source
  asset integrity, non-reentrant queries, temporary-code lifecycle, dynamic
  architecture preservation, and rendered proof in both Plugin and Direct
  modes.
- Audit incidents use a single responsive page with readable causes, contained
  payloads, and copy controls. Legacy Sandbox audit links point to that page.

### Fixed

- Prevent activation fatals when WooCommerce and Stonewright share Jetpack
  Autoloader by keeping root dev autoload metadata out of Jetpack's optimized
  classmap and eliminating development-only paths from release archives.
- Release checks now test WooCommerce co-activation and reject archives with
  missing Composer-manifest targets, including after release-only exclusions
  are applied.
- The WooCommerce compatibility gate now installs the extracted release archive
  rather than the source tree.
- Preserve every packaged generic skill during fresh-install seeding without
  weakening the credential guard for user-created skills.
- Keep Sandbox primary-action labels readable and expose a working copy action
  for one-time custom-code approval tokens.
- Publish the supported public beta as GitHub's latest release so repository
  release links and the native update checker can discover it.
