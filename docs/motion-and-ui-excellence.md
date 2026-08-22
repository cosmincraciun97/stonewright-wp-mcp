# Motion and UI excellence

Stonewright treats motion as a verified design contract, not as arbitrary CSS
or a successful metadata write. The workflow is capability discovery, semantic
DesignSpec, signed plan, dry-run, one guarded write, structural readback,
editor/frontend evidence, and quality evaluation.

## Current renderer support

| Renderer | Current support | Write boundary |
| --- | --- | --- |
| Gutenberg/FSE | Seven bundled, static-first presets with conditional assets | One consolidated block-class mutation; static blocks may require the editor finalizer |
| Elementor V3 | Native entrance and licensed motion controls discovered from the live widget schema | Sparse batch only; complete schema identity and a current tree hash are required |
| Elementor V4 Atomic | Live interactions schema and official mutation-primitives discovery | Native interactions write remains unsupported when any official primitive or dedicated typed adapter is missing |

V4 is experimental. Stonewright does not write interactions through
`update-node`, direct `_elementor_data`, raw REST, PHP metadata, or WP-CLI. The
same rule applies when Elementor can render a shape that Stonewright cannot yet
round-trip safely.

## Product guarantees

- Bundled entrance motion is static-first: without JavaScript, important
  content stays visible and usable.
- `prefers-reduced-motion` is applied at load and when the preference changes.
- Hover effects require an equivalent `:focus-visible` path.
- Plan operations are site-signed and bound to the preset registry, asset
  checksums, renderer, capability digest, and design direction when present.
- V3 evidence binds the intended motion capability to the exact live schema,
  runtime fingerprint, source plugin, and source version.
- `design-quality-check` accepts optional motion and UI evidence; failures
  affect the real and persisted verdict, while missing measurements remain
  `not_checked`.

## Release limitations

- V4 interactions patch and full V4 class/variable style-system parity are not
  release-ready on runtimes that do not expose the complete official mutation
  stack.
- Provider adapters, Interactivity API experiments, and additional animation
  engines are outside the current supported surface and are never hidden
  fallbacks.
- Editor reopen, desktop/tablet/mobile frontend checks, reduced-motion, no-JS,
  and controlled performance runs remain required UAT evidence before release.
