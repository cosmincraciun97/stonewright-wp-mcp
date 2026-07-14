# Release Notes

This directory stores human-written release notes for Stonewright alpha builds.
Each release note should describe user-visible behavior, shipped assets, and the
verification commands run before publishing.

Use [checklist.md](checklist.md) before tagging a release.

## Maintainer Rules

- Keep release notes tied to product behavior, compatibility, control gates, and
  installable assets.
- Do not turn release notes into marketing copy.
- Do not include private site memory, credentials, internal development tooling,
  generated-authorship claims, or unattributed third-party project text. Name
  upstream-derived changes and preserve required notices.
- When a release changes a compact MCP profile, name the affected profile and
  the visible recovery behavior.
- When a release changes a write path, state whether permissions, backups,
  confirmation tokens, validation, or audit logging changed.

## Recent Tracks

| Track | Releases | Focus |
|---|---:|---|
| Strict-client startup | alpha.49-alpha.64 | Low-tools support, grouped inventories, optional profile verification, strict output schema compatibility, companion startup reliability, builder unblock profiles, MCP-only recovery guardrails, local WP-CLI runtime diagnostics, deep WP-CLI health checks, first-class PHP runtime routing, composite Theme Builder/content-model flows, Codex setup refresh, and fresh activation cleanup |
| Compact profiles | alpha.20-alpha.48 | Profile-aware discovery, recovery diagnostics, and smaller startup/preflight payloads |
| Fast composite writes | alpha.9-alpha.19 | Workflow preflight, visual gates, batch writes, prompt skills, and implementation contracts |
| Foundations | alpha.2-alpha.8 | Core abilities, release packaging, dependency policy, Elementor schema grouping, and PHP compatibility |

## Public Release Notes

- [1.0.0-alpha.66](1.0.0-alpha.66.md)
- [1.0.0-alpha.65](1.0.0-alpha.65.md)
- [1.0.0-alpha.65 roadmap audit](1.0.0-alpha.65-roadmap-audit.md)
- [1.0.0-alpha.64](1.0.0-alpha.64.md)
- [1.0.0-alpha.63](1.0.0-alpha.63.md)
- [1.0.0-alpha.62](1.0.0-alpha.62.md)
- [1.0.0-alpha.61](1.0.0-alpha.61.md)
- [1.0.0-alpha.60](1.0.0-alpha.60.md)
- [1.0.0-alpha.59](1.0.0-alpha.59.md)
- [1.0.0-alpha.58](1.0.0-alpha.58.md)
- [1.0.0-alpha.57](1.0.0-alpha.57.md)
- [1.0.0-alpha.56](1.0.0-alpha.56.md)
- [1.0.0-alpha.55](1.0.0-alpha.55.md)
- [1.0.0-alpha.54](1.0.0-alpha.54.md)
- [1.0.0-alpha.53](1.0.0-alpha.53.md)
- [1.0.0-alpha.52](1.0.0-alpha.52.md)
- [1.0.0-alpha.51](1.0.0-alpha.51.md)
- [1.0.0-alpha.50](1.0.0-alpha.50.md)
- [1.0.0-alpha.49](1.0.0-alpha.49.md)
- [1.0.0-alpha.48](1.0.0-alpha.48.md)
- [1.0.0-alpha.47](1.0.0-alpha.47.md)
- [1.0.0-alpha.46](1.0.0-alpha.46.md)
- [1.0.0-alpha.45](1.0.0-alpha.45.md)
- [1.0.0-alpha.44](1.0.0-alpha.44.md)
- [1.0.0-alpha.43](1.0.0-alpha.43.md)
- [1.0.0-alpha.42](1.0.0-alpha.42.md)
- [1.0.0-alpha.41](1.0.0-alpha.41.md)
- [1.0.0-alpha.40](1.0.0-alpha.40.md)
- [1.0.0-alpha.39](1.0.0-alpha.39.md)
- [1.0.0-alpha.38](1.0.0-alpha.38.md)
- [1.0.0-alpha.37](1.0.0-alpha.37.md)
- [1.0.0-alpha.36](1.0.0-alpha.36.md)
- [1.0.0-alpha.35](1.0.0-alpha.35.md)
- [1.0.0-alpha.34](1.0.0-alpha.34.md)
- [1.0.0-alpha.33](1.0.0-alpha.33.md)
- [1.0.0-alpha.32](1.0.0-alpha.32.md)
- [1.0.0-alpha.31](1.0.0-alpha.31.md)
- [1.0.0-alpha.30](1.0.0-alpha.30.md)
- [1.0.0-alpha.29](1.0.0-alpha.29.md)
- [1.0.0-alpha.28](1.0.0-alpha.28.md)
- [1.0.0-alpha.27](1.0.0-alpha.27.md)
- [1.0.0-alpha.26](1.0.0-alpha.26.md)
- [1.0.0-alpha.25](1.0.0-alpha.25.md)
- [1.0.0-alpha.24](1.0.0-alpha.24.md)
- [1.0.0-alpha.23](1.0.0-alpha.23.md)
- [1.0.0-alpha.22](1.0.0-alpha.22.md)
- [1.0.0-alpha.21](1.0.0-alpha.21.md)
- [1.0.0-alpha.20](1.0.0-alpha.20.md)
- [1.0.0-alpha.19](1.0.0-alpha.19.md)
- [1.0.0-alpha.18](1.0.0-alpha.18.md)
- [1.0.0-alpha.17](1.0.0-alpha.17.md)
- [1.0.0-alpha.16](1.0.0-alpha.16.md)
- [1.0.0-alpha.14](1.0.0-alpha.14.md)
- [1.0.0-alpha.13](1.0.0-alpha.13.md)
- [1.0.0-alpha.12](1.0.0-alpha.12.md)
- [1.0.0-alpha.11](1.0.0-alpha.11.md)
- [1.0.0-alpha.10](1.0.0-alpha.10.md)
- [1.0.0-alpha.9](1.0.0-alpha.9.md)
- [1.0.0-alpha.8](1.0.0-alpha.8.md)
- [1.0.0-alpha.7](1.0.0-alpha.7.md)
- [1.0.0-alpha.6](1.0.0-alpha.6.md)
- [1.0.0-alpha.5](1.0.0-alpha.5.md)
- [1.0.0-alpha.4](1.0.0-alpha.4.md)
- [1.0.0-alpha.3](1.0.0-alpha.3.md)
- [1.0.0-alpha.2](1.0.0-alpha.2.md)
