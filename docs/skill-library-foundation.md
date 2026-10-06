# Skill library foundation

The `Stonewright\WpMcp\SkillLibrary` namespace separates Markdown exchange,
record validation, source resolution, lifecycle decisions, and repository
coordination. These components are not registered as WordPress abilities or
admin routes yet. They require authenticated persistence adapters before they
can replace the current site service.

## Documents and records

`DocumentCodec` accepts bounded UTF-8 Markdown with a supported scalar front
matter subset. It rejects ambiguous YAML structures, duplicate metadata keys,
binary content, and documents larger than 1 MiB. Export is deterministic.
`RecordRules` checks understood metadata while preserving omitted preferences
and other serializable metadata. Trigger checks accept text in any language;
a host can supply a stricter clarity policy.

Imports are inspected without a write. The skill identity comes from the file
name, so a review carries the SHA-256 of the exact bytes and a review hash
that binds the identity to those bytes. Confirmation derives the proposed
record again from the reviewed file name and bytes, and refuses it when the
bytes, the identity, or the review hash no longer match. Imported records
remain disabled drafts. A hash proves that the reviewed file is unchanged; an
authenticated write boundary must separately verify a server-bound review
receipt, and it receives the review hash for that check. Import never
overwrites an existing skill and never takes a reserved built-in identity.

## Version constraints

Version constraints accept the three shapes that rows stored by earlier
releases already contain in `version_constraints_json`:

- `[]` (or `{}` in front matter): no constraint.
- A map of plugin slug to `"required"` or a version expression, such as
  `{"elementor":"required"}` or `{"elementor":">=3.16"}`. Every listed
  plugin must be present and compatible.
- `{"any_of":"slug-a|slug-b|slug-c"}`: any one listed plugin that is present
  satisfies the constraint.

The codec and record validation accept these shapes and reject repeated
component names, empty `any_of` alternatives, and other lists. The runtime
compatibility contract interprets version expressions; each `any_of`
alternative is checked as a required plugin.

## Enabling and visibility

`enabled` records the site's choice. Enabling promotes a draft to active;
stale and retired skills cannot be enabled again. Only lint findings can block
activation. Local, imported, and external guidance must pass the trigger,
version-constraint, and tool-reference checks. Shipped built-in and playbook
text is trusted, so only conflict and lifecycle findings block it.

Missing plugin components never block enabling, for any source. Visibility is
decided at read time: an enabled, active skill is hidden from agents while a
required component is missing, and `VisibilityRules::missing()` reports which
requirements are unavailable. Sites seeded by earlier releases therefore keep
every built-in enabled while its components are absent.

Disabling, trashing, and permanent deletion never rerun write validators, so
stored text that a newer check rejects can still be withdrawn. Rollback
validates the target snapshot only when the result would be active.

## Source and lifecycle boundaries

Pack entries have physical keys that are separate from persistent site
identities. A refresh requires an explicit map of canonical identities and
refuses non-canonical, ambiguous, or duplicate targets; stored identities are
compared after the same normalization that every write applies. The catalog
also receives the list of known built-in identities. Those identities stay
reserved even before their pack entries are seeded, so neither a local save
nor an import can create a skill with one of them. External records cannot
supply local ids, verification credits, revision history, or product
provenance.

Local edits preserve previous revisions. Semantic changes to verified or
candidate guidance invalidate its live verification and return it to a
disabled draft. Product and external skills cannot be trashed through local
operations. Trashed skills are excluded from normal lookup and routing;
restoring a local skill keeps it disabled until activation checks pass.

## Integration contracts

`Repository` must provide unique insertion, revision comparison, and atomic
replacement with an immutable prior snapshot. `MutationBoundary` must enforce
the actual site's permissions, mode, confirmation-token binding, import
receipt, and audit contract. Neither port has a default production adapter.
Activation lint requires the registered tool catalog. Runtime compatibility
checks decide visibility, not whether a skill can be enabled.

Existing storage encodings, source identifiers, revision mappings, built-in
identities, and update preservation must be verified before wiring these
components into WordPress. New unit tests exercise the logical contracts with
synthetic repositories; they do not establish site migration compatibility.
