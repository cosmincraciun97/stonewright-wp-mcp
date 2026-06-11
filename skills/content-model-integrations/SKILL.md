---
name: content-model-integrations
description: Use Stonewright for ACF, ACPT, Meta Box, ASE, Pods, custom fields, custom post types, taxonomies, option pages, field groups, repeaters, relationships, and content-model audits or migrations.
---

# Content Model Integrations

Use this when task mentions ACF, ACPT, Meta Box, ASE, Pods, custom fields,
field groups, post types, taxonomies, option pages, relationships, repeaters,
flexible content, or content-model migration.

## First Call

Call `stonewright/workflow-preflight` with the user task, surface `wordpress`
or the plugin surface (`acf`, `acpt`, `meta-box`, `ase`, `pods`), and intent.
Read `fast_path.specializations` before choosing tools.

Then call:
- `stonewright/site-plugins-list`
- `stonewright/wp-cli-status`
- `stonewright/wp-cli-discover`

Use `stonewright/wp-cli-run` only with argv tokens. Never use `wp eval`,
`wp eval-file`, `wp shell`, `wp package`, `--exec`, or `--require`.

## Discovery

Before writing, answer these:
- Which plugin owns the schema?
- Is the plugin active, and what version?
- Which post types, taxonomies, users, terms, comments, or option pages are
  valid value targets?
- Which REST routes or WP-CLI command groups are available on this site?
- Which fields are simple meta values and which are structured values such as
  repeaters, groups, flexible content, relationships, or galleries?

Do not guess hidden storage keys. Discover schema first.

## Plugin Notes

### ACF

Prefer ACF REST field objects when the field group has REST enabled. ACF exposes
fields through normal WP REST endpoints when configured. Use `acf` payloads for
REST-compatible writes and read back the `acf` object after writes.

Useful docs:
- https://www.advancedcustomfields.com/resources/wp-rest-api-integration/
- https://www.advancedcustomfields.com/resources/get_field/
- https://www.advancedcustomfields.com/resources/update_field/
- https://www.advancedcustomfields.com/resources/post-types-and-taxonomies/

### ACPT

Keep the hierarchy intact: post type or taxonomy, meta group, meta box, meta
field, option page, value. Prefer ACPT documented APIs or REST integration when
enabled. Verify each hierarchy level after writes.

Useful docs:
- https://docs.acpt.io/
- https://docs.acpt.io/basics/custom-post-types
- https://docs.acpt.io/tools/custom-apis
- https://docs.acpt.io/meta-fields/field-types
- https://docs.acpt.io/integrations/api-rest-field-integration

### Meta Box

Prefer MB REST API when active. Treat relationships and settings pages as schema
objects, not plain text meta. Use core meta writes only for simple registered
fields after permission and schema checks.

Useful docs:
- https://docs.metabox.io/custom-fields/
- https://docs.metabox.io/extensions/meta-box-builder/
- https://docs.metabox.io/extensions/mb-rest-api/
- https://docs.metabox.io/extensions/mb-relationships/
- https://docs.metabox.io/extensions/mb-settings-page/

### ASE

ASE Pro content model work can include field groups, post types, taxonomies,
option pages, and values on posts, terms, and option pages. Discover target
shape first; top-level object edits may merge, arrays often need replacement.

Useful docs:
- https://www.wpase.com/features/custom-content-types/
- https://www.wpase.com/documentation/custom-field-types/
- https://www.wpase.com/documentation/

### Pods

Prefer Pods REST endpoints or `wp pods` / `wp pods-api` commands when present.
Create groups before fields. Read back pod, group, and field structure after
writes. Keep Advanced Content Types and settings pages distinct from post types.

Useful docs:
- https://pods.io/
- https://docs.pods.io/advanced-topics/rest-api/
- https://docs.pods.io/code/rest-api-endpoints/
- https://docs.pods.io/code/wp-cli-commands/
- https://docs.pods.io/code/wp-cli-commands/wp-pods-api/

## Safe Write Pattern

1. Call `stonewright/context-bootstrap`; keep `stonewright_context_token`.
2. Discover plugin, schema, command groups, and value targets.
3. Create a small write plan with exact target IDs and field names.
4. For post/page content or meta writes, rely on Stonewright content abilities
   so snapshots and permissions run.
5. For plugin command writes, use `stonewright/wp-cli-run` with argv tokens and
   `stonewright_context_token`.
6. Verify by reading back changed schema or values.
7. For visible output, verify with external browser MCP.

## Bulk And Migration

For migrations between field plugins, do not write immediately. First export a
neutral model:
- entities: post types, taxonomies, option pages
- field groups and fields
- target rules
- value types and repeatable structure
- unsupported or lossy features

Return the neutral model and loss report before applying changes.
