# Release notes

Stonewright's supported public release history starts with the first beta.

## Policy

On every release:

1. Add `docs/releases/<version>.md` for the new version.
2. Update root and plugin `CHANGELOG.md` entries.
3. Keep supported release notes accurate and free of credentials or private
   project data.
4. Rebuild distributable archives under `dist/`.
5. Complete [checklist.md](checklist.md) and a filled
   [acceptance-report-template.md](acceptance-report-template.md) (private
   copies may omit git if they contain environment detail).
6. Run tier-1 client smoke using
   [client-acceptance-template.md](client-acceptance-template.md).

Historical licensing decisions live in `docs/licensing.md` and
`docs/upstream-code-reuse.md`. Public history may be rewritten only for a
maintainer-approved privacy or credential incident, with fresh release
artifacts and verification afterward.

## Templates

| Document | Purpose |
|---|---|
| [checklist.md](checklist.md) | Automated + manual release gates |
| [acceptance-report-template.md](acceptance-report-template.md) | Human roll-up for a release candidate |
| [client-acceptance-template.md](client-acceptance-template.md) | Per-client certification evidence |
