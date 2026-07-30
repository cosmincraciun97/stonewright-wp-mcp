---
{
  "name": "Content model additive only",
  "description": "Never full-import CPT UI options on live; add/edit one type at a time; no silent env-to-env overwrite.",
  "triggers": [
    "CPT UI",
    "import",
    "export",
    "taxonomy",
    "custom post type",
    "ACF field group",
    "overwrite",
    "sync environments"
  ],
  "enabled": true,
  "updated_at": "2026-07-16T00:00:00.000Z"
}
---

# Content model: additive only

## Hard rules

1. **Never** use CPT UI Tools → full Import Post Types / Import Taxonomies to add a single type on a site that already has models.  
   - Import **replaces** the whole `cptui_post_types` / `cptui_taxonomies` options.  
   - Risk: wipe existing CPTs/taxonomies.  
2. **Always** create via **Add New** (merge/add).  
3. **Never** bulk-transfer models, options, ACF groups, or content from another environment unless the user **explicitly** requests that transfer.  
4. Prefer additive admin actions only — no silent overwrite of production config.

## Edits

- CPT UI edit forms need `cpt_original=<slug>` and `cpt_type_status=edit`.  
- Without them the UI may report “slug already registered” or leave partial state.  
- Always verify the post type / taxonomy name field matches the intended slug before submit.
