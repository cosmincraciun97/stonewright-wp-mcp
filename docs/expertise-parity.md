# Expertise and public capability parity

Status is evidence, not marketing. `verified` means the curriculum and typed
Stonewright capability contract pass the eval gate; runtime activation still
requires the matching plugin/version. `stable` additionally needs two runtime
fingerprints or maintainer approval. Missing adapters remain `draft` or
`absent`.

| Tier | Integration | Implementation evidence | Eval status | Pack status |
|---|---|---|---|---|
| P0 | WordPress content/media/taxonomy/users/menus | Native typed abilities and bulk paths | 12/12, zero critical | verified |
| P0 | Gutenberg/FSE/theme.json | Native block/FSE abilities and readback | 12/12, zero critical | verified |
| P0 | Elementor V3 | Live schemas, strict compiler, batch/readback/rollback | 12/12, zero critical; runtime-gated | verified |
| P0 | Elementor V4 Atomic | Discovery only; editor adapter lands in PR9/PR10 | Curriculum only | draft |
| P0 | Design/Figma/image → WordPress | DesignEvidence and native-first planner | 12/12, zero critical | verified |
| P0 | Theme Builder | Typed template/condition composite | 12/12; Elementor runtime-gated | verified |
| P0 | WooCommerce | Typed/native catalog workflow where runtime supports it | 12/12; Woo runtime-gated | verified |
| P0 | ACF/CPT/taxonomy/options/dynamic data | Discovery, typed core paths, loop flow | 12/12; add-on runtime-gated | verified |
| P0 | Security/write/recovery | Permissions, context, backup, audit, confirmation, rollback | 12/12, zero critical | verified |
| P0 | Visual/responsive verification | Browser-gated verification contract and repair loop | 12/12, zero critical | verified |
| P1 | Bricks, Divi 5, Beaver Builder, Breakdance, WPBakery, Etch, Mosaic | No typed adapters yet | none | absent |
| P1 | GeneratePress/Kadence | Specialization work pending | none | absent |
| P1 | Forms and field/data plugin families | Runtime guidance exists; typed family adapters pending | none | absent |
| P2 | Bricksforge, Dynamic Shortcodes, Code Snippets, SEO suites | Not implemented | none | absent |

No row becomes stable because a README or skill mentions it.
