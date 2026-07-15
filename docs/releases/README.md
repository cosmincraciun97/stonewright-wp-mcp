# Release notes

Stonewright keeps release notes for the **most recent five published versions**
plus an optional unreleased draft.

## Policy

On every release:

1. Add `docs/releases/1.0.0-alpha.N.md` for the new version.
2. Update root and plugin `CHANGELOG.md` entries.
3. Remove the oldest note so at most five versioned notes remain.
4. Rebuild distributable archives under `dist/` for the retained versions only.

Historical licensing decisions live in `docs/licensing.md` and
`docs/upstream-code-reuse.md` so they survive changelog retention.

Git history is never rewritten to hide older tags.
