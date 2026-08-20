---
name: seo-optimize
description: Use when editing SEO titles, descriptions, canonicals, robots, or focus keywords with Yoast, Rank Math, or All in One SEO.
version_constraints: {"any_of": "yoast-seo|rank-math|all-in-one-seo"}
---

# SEO Optimize

Use this when a supported SEO plugin is active and the task is per-post title,
meta description, canonical, robots/noindex, or focus keyword.

Gating uses IntegrationCatalog ids (`yoast-seo`, `rank-math`,
`all-in-one-seo`). `stonewright-seo-status` reports adapter ids: `yoast`,
`rankmath`, `aioseo` (and `seopress` when that plugin is the one loaded).
Do not mix the two id sets in constraints or in `plugin` comparisons.

## First call

Call `stonewright-task-start` with surface `wordpress` and intent `write`
(or `read`). Then:

1. `stonewright-site-plugins-list`
2. `stonewright-seo-status` — `active`, `plugin`, `label`, `sitemap`
3. `stonewright-seo-meta-get` with the target `post_id`

If `active` is false, stop. There is no typed core-sitemap meta writer.

## Typed surface (this is the whole set)

| MCP tool | Ability | Job |
|---|---|---|
| `stonewright-seo-status` | `stonewright/seo-status` | Which adapter + sitemap path |
| `stonewright-seo-meta-get` | `stonewright/seo-meta-get` | Normalized meta for one post |
| `stonewright-seo-meta-update` | `stonewright/seo-meta-update` | Patch title / description / focus_keyword / canonical / noindex |

`stonewright-seo-meta-update` snapshots the post, then writes the adapter
meta keys. Pass only fields you are changing. Production-safe still uses
the usual post-edit permission; this ability is not confirmation-token
gated in code.

Related: `stonewright-settings-get` / `stonewright-settings-update` for
allowlisted **WordPress** options only. `stonewright-content-get-page` to
confirm the post exists.

## Per-plugin meta map

Normalized fields always are: `title`, `description`, `focus_keyword`,
`canonical`, `noindex` (bool). Storage keys from `SeoAdapter::meta_keys`:

| Adapter `plugin` | title | description | focus_keyword | canonical | noindex |
|---|---|---|---|---|---|
| `yoast` | `_yoast_wpseo_title` | `_yoast_wpseo_metadesc` | `_yoast_wpseo_focuskw` | `_yoast_wpseo_canonical` | `_yoast_wpseo_meta-robots-noindex` |
| `rankmath` | `rank_math_title` | `rank_math_description` | `rank_math_focus_keyword` | `rank_math_canonical_url` | `rank_math_robots` |
| `aioseo` | `_aioseo_title` | `_aioseo_description` | `_aioseo_keywords` | `_aioseo_canonical_url` | `_aioseo_noindex` |
| `seopress` | `_seopress_titles_title` | `_seopress_titles_desc` | `_seopress_analysis_target_kw` | `_seopress_robots_canonical` | `_seopress_robots_index` |

`noindex` readback is true when the raw meta is `1` / `true` / `noindex` /
`on`, or when an unserialized array contains `noindex`. Update writes
`'1'` or `'0'` into that key. Rank Math normally stores an array in
`rank_math_robots`; the adapter still writes the scalar. After update,
read `stonewright-seo-meta-get`. If the plugin UI disagrees, stop and
report the adapter limit — do not invent a robots-array writer.

Do not guess other keys (`_yoast_wpseo_metakeywords`, Open Graph, Twitter,
schema graphs). They are not in this adapter.

## Titles, canonicals, robots

1. Read current meta. Empty strings mean "plugin default / template", not
   "delete the tag".
2. Patch with `stonewright-seo-meta-update`:
   - `title` — per-post title. This is not `blogname`.
   - `description` — meta description.
   - `canonical` — full URL string. Do not write `'/'` or `'#'`.
   - `noindex` — boolean. True means noindex per the adapter's key.
   - `focus_keyword` — string. Not a ranking guarantee.
3. Read back. Compare `plugin` to what status reported.
4. Sitemap URL from status is a hint (`/sitemap_index.xml`, `/sitemap.xml`).
   It is not a writer. Do not regenerate sitemaps through php-execute.

Site identity that SEO plugins often inherit (`blogname`,
`blogdescription`) goes through `stonewright-settings-get` /
`stonewright-settings-update`. Allowlist is only:

`blogname`, `blogdescription`, `site_icon`, `timezone_string`,
`date_format`, `time_format`, `start_of_week`, `posts_per_page`,
`default_comment_status`, `default_ping_status`, `users_can_register`,
`default_role`, `show_on_front`, `page_on_front`, `page_for_posts`.

`siteurl` / `home` are blocked. Unknown keys are skipped, not stored.

## Honest limits — no typed AIOSEO schema writes

There is no typed AIOSEO (or Yoast / Rank Math) schema-graph, FAQ,
breadcrumb, or social-preview writer. no typed AIOSEO schema writes —
use settings-get/update for WordPress identity only.

`stonewright-settings-get` / `stonewright-settings-update` will **not**
persist AIOSEO graph JSON, Yoast schema pieces, or Rank Math schema
keys. Those option keys are outside the allowlist. If the operator asks
for schema markup:

- Stop. Say the typed surface is title / description / canonical /
  robots / focus keyword plus WP identity settings.
- Do not php-execute guessed `aioseo_options` blobs.
- Do not invent `stonewright-seo-schema-update`.

AIOSEO (and others) may also keep data in plugin tables. Adapter
readback is post meta only. If the editor UI still shows old schema,
that is expected. Report it.

## SEOPress

If status `plugin` is `seopress`, the same get/update path works. This
pack's presence gate does not include `seopress`, so the body may be
hidden on a SEOPress-only site. Use the typed SEO tools directly.

## php-execute / WP-CLI

Do not replace `seo-meta-update` with `update_post_meta` in PHP. Do not
run `wp eval`. Tokenized `stonewright-wp-cli-run` only after
`stonewright-wp-cli-discover` lists a real command path, and only for
jobs these three abilities cannot do (for example a plugin's own CLI
sitemap ping). Prefer the typed meta tools for the five fields above.
