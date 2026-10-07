# Skill library foundation

The `Stonewright\WpMcp\SkillLibrary` namespace separates Markdown exchange,
record validation, source resolution, lifecycle decisions, and repository
coordination. The WordPress adapters live in `SkillLibrary\Site` and are
described under [Site integration](#site-integration).

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
overwrites an existing skill and never takes a reserved built-in identity;
either collision answers HTTP 409.

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

A fourth form names a provider instead of a plugin:
`{"provider:elementor-native":"required"}`. The `provider:` prefix cannot occur
in a plugin slug. Only known provider ids are accepted, and the only expression
is `required`; `elementor-native` is present when Elementor's own MCP abilities
are registered. Front matter can write the same requirement as
`requires_provider: elementor-native`, which the codec merges into
`version_constraints`. A missing provider hides the skill like a missing plugin
and never blocks enabling it.

## Enabling and visibility

`enabled` records the site's choice. Enabling promotes a draft to active;
stale and retired skills cannot be enabled again. Only lint findings can block
activation. Local, imported, and external guidance must pass the trigger,
version-constraint, and tool-reference checks. Shipped built-in and playbook
text is trusted, so only conflict and lifecycle findings block it. Lint reports
`missing_trigger`, `missing_version_constraints`, `unresolved_conflicts`,
`stale_record` (a stale, retired, or trashed record), and
`unavailable_tool:<ability>` (a referenced `stonewright/` ability that is not
registered).

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
receipt, and audit contract. Activation lint requires the registered tool
catalog. Runtime compatibility checks decide visibility, not whether a skill
can be enabled.

## Site integration

`SkillLibraryService::open()` is the one place where the site adapters are
assembled; every caller reads and writes skills through it. Reads return the
stored row as text plus decoded `version_constraints` and `conflicts`, and
never include the trash.

- **Storage.** `WordPressRepository` uses the existing
  `{prefix}stonewright_skills` and `{prefix}stonewright_skill_versions` tables,
  created and upgraded in place by `SkillTables` (schema versions `1.3` and
  `1.0`). A write replaces a row only while it still holds the revision and
  lifecycle state that was read. Changing text, provenance, evidence, or
  exposure preferences records the previous row, every value as text, as one
  revision row in the same transaction; enabling, disabling, trashing, and
  restoring change only the lifecycle columns. Times are UTC.
- **Boundary.** `WordPressBoundary` speaks for the channel a change arrives
  through. The skills screen and the REST routes require `manage_options` at
  the moment of the write, and studio routes also need a current REST nonce.
  Abilities rely on their own permission callback. Only the system channel may
  refresh the bundled pack or write verified knowledge evidence. In
  production-safe mode, permanent deletion needs a confirmation token issued
  for `stonewright/skills-destroy` with `{"id": <skill id>}`. Imports need the
  receipt that the review issued: an HMAC over the review hash, the reviewing
  user, and an expiry. Audit events carry bounded metadata only, and the
  bundled pack refresh writes none. When the ability kernel is auditing a call
  under the same name as the event, such as `stonewright/skills-save`, the
  event's details go on that call's row instead of a second row with that name;
  the other channels write their own row.
- **Bundled pack.** `BundledPack` maps `skills/<name>/SKILL.md` to
  `stonewright-<name>` (source `builtin`) and `skills/playbooks/<name>.md` to
  `playbook-<name>` (source `playbook`). Activation and every version change
  insert missing entries, update changed text while keeping the site's enable
  choices, take a bundled identity back from a row of another source after
  keeping that row as a revision, and retire entries that no longer ship.
- **Local saves.** Saves never set provenance: new skills are local (`user`)
  and existing ones keep their source. A save without description text uses
  the title as trigger text. Echoing a stored evidence value back is not a
  claim; changing it is refused. A save that carries a stale revision, that
  carries the revision of a skill that no longer exists, or that finds the row
  changed while it was being stored answers HTTP 409 and changes nothing. The
  skill editor sends the revision it read; the `stonewright/skills-save`
  ability and `POST /stonewright/v1/skills` accept one, and a save without it
  is not checked. A save over a built-in or playbook skill, or over a reserved
  built-in slug, is refused with 403.
- **Knowledge bundles.** `import_bundle_skill()` adds the skills of a knowledge
  bundle as disabled drafts, whatever exposure, status, or provenance an entry
  claims, and never replaces a skill: an identity stored in any state, the
  trash included, or reserved for a built-in skill is skipped, and so is an
  entry the library refuses. The `stonewright/knowledge-import` result reports
  the number added in `skills_imported` and the skipped slugs in
  `skills_skipped` (at most 50), and the Memory page shows how many skills were
  added and skipped.
- **Learning drafts.** `stonewright/learning-record` saves its optional draft
  skill through the same save, but revises only its own draft for the topic: a
  local, draft skill with the same topic. Any other skill under the requested
  slug, in any state, is left unchanged, and the result reports
  `stonewright_skill_slug_taken` in `skill_error`.
- **REST.** `DELETE /stonewright/v1/skills/{id}` moves a local skill to the
  trash. It answers 404 for an unknown id and 403 for a built-in or playbook
  skill. Permanent deletion uses the `skills-studio` routes.
