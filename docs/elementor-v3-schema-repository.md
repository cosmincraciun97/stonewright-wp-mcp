# Elementor V3 schema repository

Stonewright treats the active Elementor runtime as the authority for widget
settings. It does not permit an agent to invent a setting key and hope that
Elementor accepts it.

## Runtime flow

1. `stonewright-elementor-schema` lists or searches every widget registered in
   the current `widgets_manager`, including third-party widgets.
2. `summary` returns a paginated compact control map; `control` returns one
   complete control; `full` pages by section.
3. Every record carries a schema hash, runtime fingerprint, source, versions,
   expiry, and provenance.
4. `SettingsValidator` checks unknown keys, responsive suffixes, URLs, media,
   dimensions, dynamic/global bindings, and nested repeater fields.
5. `ElementorData::write()` performs a final tree guard before `_elementor_data`
   can be persisted. V4 atomic nodes remain isolated for the V4 validator.

The fingerprint includes WordPress, Elementor Core/Pro, active Elementor
add-ons, locale, and feature/experiment flags. Plugin activation, deactivation,
upgrade, active-plugin changes, and tracked Elementor experiment changes clear
all cached schema shards.

`stonewright-elementor-schema` covers widgets only. A request for `container`,
`section` or `column` fails with `stonewright_elementor_use_container_schema`; read
those with `stonewright-elementor-v3-container-schema`.

## Bundled catalog

The former 5.85 MB JSON+PHP duplicate manifest is gone. The generated catalog
uses one compact PHP index and 95 lazy PHP shards. Normal lookup loads the index
plus only the requested widget shard. Generated artifacts contain no absolute
developer paths.

Regenerate with:

```bash
php plugin/bin/manifest-synthesize.php
```

Compare catalog versions with:

```bash
cd plugin
composer elementor:schema-diff -- /path/to/before/index.php /path/to/after/index.php
```

The bundled shard supplies verified fallback knowledge such as
`required_for_render`; live controls always win. An unregistered third-party
widget is blocked with `capture_required=true` instead of being written raw.

## Sparse LLM schema

`stonewright-elementor-schema` runs live controls through
`PlainLlmSchemaConverter` before returning them. That converter unwraps
`{$$type, value}` envelopes, collapses duplicate union branches, enriches enums
from control options, and omits Elementor Pro controls when Pro is inactive.
`mode=summary` returns the key, type, label, section and responsive flag of each
control, paginated, with `required_for_render`. Compatibility
`stonewright-elementor-v3-get-widget-schema` defaults to compact Content/Style/
Advanced groups (`defaults_omitted: true`); use `responseMode=full` only when
defaults are required for the next write.

Agents should plan against that compact schema, then send only the settings they
actually intend to change.

## Sparse writes

After schema and evidence validation, `SparseSettingsNormalizer` sparsifies the
**agent-supplied patch**, not the live document:

- New widget/container payloads drop empty default objects and, for widgets,
  known schema defaults that were not required for render.
- Updates merge the sparsified patch onto existing element settings. Live keys
  the agent did not send stay in place.
- Unknown keys are left in the payload so `SettingsValidator` remains the
  rejection gate. The normalizer never strips unknown settings to “pass”
  validation.

Use surgical `elementor-v3-batch-mutate` (or the typed add/update abilities).
Do not full-tree rewrite a document to fix one control.

## Value checks

`SettingsValidator` checks the value of a known control as well as its key.
Colour controls take hex, `rgb()`, `rgba()`, `hsl()`, `hsla()`, a CSS colour
name, `transparent`, `currentColor`, a global colour variable or an empty
string; `font` controls take a plain family name; typography weight, transform,
style and decoration come from fixed lists; slider, dimension, `box_shadow` and
`text_shadow` values need numeric parts and a unit from a fixed list; a
`__globals__` binding is `globals/<type>?id=<id>` or an empty string. Any other
value fails with `stonewright_elementor_settings_invalid` and the offending
key. See [Security](security.md#values-that-reach-generated-css).

What the checks accept, because Elementor stores it:

- An unset control may be sent as the empty string, `null` or an empty list,
  whatever its type and whichever options a select lists. A repeater is the
  exception: it takes a list of rows.
- A select or choose control whose option keys are numeric strings (a font
  weight, a column span) takes the number as well; the value is stored as the
  string, and a `value_coerced` warning says so.
- A slider or dimensions object may carry only `unit`, or only some sides, so a
  patch can change one part. The `custom` unit takes a CSS length expression
  (`calc()`, `clamp()`, `min()`, `max()`, `var()` over numbers, units and
  custom property names, up to 120 characters) instead of a number.
- A link is a URL string or an object with `url`, `is_external`, `nofollow` and
  `custom_attributes`. The target may be an absolute URL with any scheme except
  `javascript:`, `vbscript:`, `data:`, `file:` and `blob:`, a path, a query or an
  anchor, and has no spaces. A link object may carry only the options being
  changed.
- A media value is `{ id, url }` with either part empty, or a list of them.
- A repeater row may carry `_id`, `__globals__` and `__dynamic__` next to its
  fields; the targets of its bindings are the fields of the row.
- A control that Elementor hides while it holds its own default (the heading
  `size`, hidden when it is `default`) accepts that default.
- A breakpoint variant (`_tablet`, `_mobile`, `_laptop`, `_widescreen` and the
  extra breakpoints) follows its base control when that control is responsive.

## When a setting is refused

A refusal lists every rejected setting in `violations`, and each entry carries:

| Field | Content |
|---|---|
| `path`, `setting` | The path (`settings.typography_font_size`, or `settings.<repeater>.<row>.<field>`) and the top-level key. |
| `code` | `unknown_setting`, `invalid_option`, `invalid_shape`, `invalid_typography_value`, `inactive_condition`, `required_missing`, `invalid_binding`, `unknown_binding_target` or `unknown_repeater_field`. |
| `expected`, `got_type` | The shape the control takes and the type received. |
| `received_value` | `type`, a short `preview` (60 characters; credentials are redacted; an object keeps its shape but never its secrets) and `truncated`. |
| `control` | The live control: `key`, `type`, `label`, `units` for a slider or dimensions control, `responsive` with the `responsive_keys` of the active breakpoints, `options`, `default` and, for a repeater, `row_fields`. |
| `allowed_values` | The option keys of a select or choose control, up to 25. |
| `requires` | For `inactive_condition`, the settings to send with it (`{ "typography_typography": "custom" }`). |
| `example` | A settings fragment that would be accepted, derived from the live control and from the value sent. |
| `hint`, `group_controls`, `sent_as` | The known repair when the key is a breakpoint variant of a control that is not responsive, or a control group sent as an object; the alias the key was mapped from. |

`elementor-v3-batch-mutate` adds to each entry the `operation_index`, `action`,
`op_id`, the `element_id` or `parent_id` and the `widget_type`, and returns them
in `rejected_settings` (up to 40; `rejected_total` counts all of them). The
message names the first three, and the Audit Log row keeps the same message and
a `rejected_summary` with the operation, widget, setting and received type of
each.

## Compatibility

`stonewright/elementor-v3-get-widget-schema` and
`stonewright/elementor-v3-list-widgets` remain compatibility adapters over the
same repository. New clients should use `stonewright-elementor-schema`.

Editor-JavaScript schema comparison and page-resident mutation tools belong to
the separate [V3 editor adapter](elementor-v3-editor-adapter.md); this
repository does not claim that server-side and active-editor schemas have been
reconciled.
