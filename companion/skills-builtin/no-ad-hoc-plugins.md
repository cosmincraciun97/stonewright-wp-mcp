---
{
  "name": "No ad-hoc plugins",
  "description": "Never scaffold, zip, upload, or activate custom WordPress plugins as a workaround. Use tools already on the site.",
  "triggers": [
    "plugin",
    "custom plugin",
    "create plugin",
    "scaffold plugin",
    "zip plugin",
    "mu-plugin",
    "install plugin",
    "content model registration",
    "register post type"
  ],
  "enabled": true,
  "updated_at": "2026-07-16T00:00:00.000Z"
}
---

# No ad-hoc plugins

## Hard rule

- **Never create** custom WordPress plugins as an agent workaround.
- **Never install/activate** agent-made plugin zips.
- Do not propose a mini-plugin when the real gap is “needs server-side PHP registration.”

## Do instead

1. Use tools already active on the site (CPT UI, ACF / field plugins, Elementor, WooCommerce, etc.).
2. Edit **existing** registered models via REST/Direct tools when available.
3. For **new** post types / taxonomies / field groups: use the site’s existing admin tooling (additive) or tell the user registration requires server-side PHP (theme/plugin) — WordPress has no REST registration endpoint.
4. Prefer Stonewright plugin mode when full runtime PHP (`php-execute`) is required and installed.

## Forbidden

- Scaffolding `*-model` plugins, drop-ins, or mu-plugins without explicit user request for that exact approach.
- Treating “install a plugin I wrote” as the default path for CPT/ACF/registration gaps.
