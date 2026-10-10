# Licensing

Stonewright ships:

- WordPress plugin: `GPL-2.0-or-later`
- Node companion (`@stonewright/companion`): `MIT`
- Stonewright Visual package: `GPL-2.0-or-later`
- Skill packs (`skills/`, shipped inside the plugin): `GPL-2.0-or-later`
- Documentation (`docs/`): `GPL-2.0-or-later`

## Third-party code

Third-party packages and files keep their own copyright, SPDX, and license
notices. Source imported into a Stonewright component keeps its original
notices, must carry a license compatible with that component, and records its
source and modifications in the file header.

The MIT companion must not receive copyleft-licensed code.
`composer provenance:lint` checks this boundary together with the component
license metadata.
