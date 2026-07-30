# Expertise and public capability parity

Status is evidence, not marketing. `candidate` means the curriculum contract
passes but exact live runtime evidence is still missing. `verified` requires a
matching fixture/schema fingerprint plus editor, frontend, and readback proof.
`stable` additionally needs two runtime
fingerprints or maintainer approval. Missing adapters remain `draft` or
`absent`.

| Tier | Integration | Implementation evidence | Eval status | Pack status |
|---|---|---|---|---|
| P0 | WordPress content/media/taxonomy/users/menus | Native typed abilities and bulk paths | Curriculum 12/12; live fixture evidence pending | candidate |
| P0 | Gutenberg/FSE/theme.json | Native block/FSE abilities and readback | Curriculum 12/12; live fixture evidence pending | candidate |
| P0 | Elementor V3 | Live schemas, strict compiler, batch/readback/rollback | Curriculum 12/12; controlled-site matrix pending | candidate |
| P0 | Elementor V4 Atomic | Discovery only; editor adapter lands in PR9/PR10 | Curriculum only | draft |
| P0 | Design/Figma/image → WordPress | DesignEvidence and native-first planner | Curriculum 12/12; browser evidence pending | candidate |
| P0 | Theme Builder | Typed template/condition composite | Curriculum 12/12; controlled-site matrix pending | candidate |
| P0 | WooCommerce | 17 typed native catalog/read abilities, Woo 10.9.4 co-activation fixture, Elementor live-schema and Woo block routing | Unit/package activation evidence; live catalog CRUD and rendered storefront fixture pending | candidate |
| P0 | ACF/CPT/taxonomy/options/dynamic data | Discovery, typed core paths, loop flow | Curriculum 12/12; add-on fixture pending | candidate |
| P0 | Security/write/recovery | Permissions, context, backup, audit, confirmation, rollback | Curriculum 12/12; runtime evidence pending | candidate |
| P0 | Visual/responsive verification | Browser-gated verification contract and repair loop | Curriculum 12/12; browser matrix pending | candidate |
| P1 | Bricks, Divi 5, Beaver Builder, Breakdance, WPBakery, Etch, Mosaic | Runtime detection only; no typed adapters | detection tests | discovery-only |
| P1 | GeneratePress, Astra, Kadence, Avada, OceanWP, Spectra One | Runtime detection only; native theme APIs still required | detection tests | discovery-only |
| P1 | GenerateBlocks, Kadence Blocks, Spectra | Runtime detection only; registered block schemas required | detection tests | discovery-only |
| P1 | Forms and field/data plugin families | Runtime detection and guidance; typed family adapters pending except ACF | detection tests | discovery-only |
| P2 | Bricksforge, Dynamic Shortcodes, Code Snippets, SEO suites | Runtime detection only; no generic write claim | detection tests | discovery-only |

`discovery-only` means Stonewright can identify the active integration and stop
blind writes. It is not typed compatibility. No row becomes verified or stable
because a README, skill, detection result, or curriculum case mentions it.

## Direct mode note

Expertise packs, curriculum fingerprints, and the table above score **plugin
mode** runtime surfaces (typed abilities, DesignSpec, Elementor engines,
site-hosted skills/memory). Direct mode exposes a smaller companion tool set
over core REST/WP-CLI with local skills and memory under `~/.stonewright/`; that
path is intentionally **unscored** here. For what Direct can and cannot do, use
the capability matrix in [direct-mode-e2e.md](direct-mode-e2e.md) rather than
treating a pack row as Direct evidence.
