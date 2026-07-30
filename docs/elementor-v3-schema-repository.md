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

## Compatibility

`stonewright/elementor-v3-get-widget-schema` and
`stonewright/elementor-v3-list-widgets` remain compatibility adapters over the
same repository. New clients should use `stonewright-elementor-schema`.

Editor-JavaScript schema comparison and page-resident mutation tools belong to
the separate V3 editor adapter in PR5; this repository does not pretend that
server-side and active-editor schemas have already been reconciled.
