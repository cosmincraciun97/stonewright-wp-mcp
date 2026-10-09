# Design

**Stonewright → Knowledge → Design** (marked EXP) holds the design direction agents follow when they
build pages: the tokens, dials and rules that say what the site should look like. Source:
`plugin/includes/Admin/Pages/DesignPage.php` and `plugin/includes/Design/Direction/`. The storage
model, the ability surface and the revision rules are in [Architecture](../architecture.md#design-direction).

## The page

- **Active direction** shows the direction agents follow now: its name and summary, the three dials
  (variance, density, motion), the rules to follow (**Do**) and to avoid (**Don't**), and the rest of
  the contract in compact groups, each shown only when the contract fills it: **Colors**,
  **Typography**, **Spacing**, **Radii**, **Elevation**, **Motion tokens** and **Components**. A
  group of more than twelve entries shows the first twelve and counts the rest. With no active
  direction the card says so.
- **Directions** lists every stored direction, not only the active one, with its state in words
  (**Active**, **Ready**, **Draft**, **Stale**, **Archived**), the first reason a direction is not
  ready, its revision and when it was updated. A ready direction has an **Activate** button and the
  active one a **Deactivate** button, each named after its direction for screen readers.
- **Import DESIGN.md** takes a pasted document and stores it as a direction.
- **Quality floor** lists the measurable rules generated pages are checked against, with their
  severity as a word and an icon. Missing evidence is not a pass.

Every action ends in a notice on the page that stays until the next one: imported and activated,
imported as a draft, imported but not activated, activated, deactivated, and an error for each.

## Importing a document

A DESIGN.md document has two halves. The front matter, between two lines of `---`, is one JSON
object: `schema_version` (`"1.0"`), `identity` (`name`, `summary`), `tokens` (`colors`,
`typography`, `spacing`, `radii`, `elevation`, `motion`), `components`, `dials` (`variance`,
`density`, `motion`, each 0 to 100), `guidance` (`do`, `avoid`), `provenance`, `waivers` and
`readiness` (`ready`, `sync_ready`, `issues`). Unknown fields are refused, not removed. The source may
be 1 MiB and the contract 256 KiB.

The prose after the front matter is never trusted. Lines that ask for credentials, name tools, try
to bypass a permission or hide markup are dropped, and what is left is stored only as rationale.

A ready document (`readiness.ready` true, no issues) is stored as ready and activated at once. A
document that is not ready is stored as a draft and the **Directions** table lists why. A document
that says it is ready but lists outstanding issues is refused.

**A refused import shows why.** The notice carries the validator's reason, for example "Direction
front matter is not valid JSON." or "Dial variance must be between 0 and 100.", or a short list of
reasons (the first five, and a count of the rest), always escaped. The reasons are kept for two
minutes for the person who submitted the form and shown once; they are never part of the address.

## Activation rules

- Only a direction whose contract is ready can be active, and exactly one is.
- The active direction cannot be archived; activate another one first.
- Saving or restoring a revision that is not ready, for the direction that is active, switches it
  off: the active pointer is cleared so the page, the brief and what agents receive agree with the
  record. An import that does this shows **Design direction imported, and switched off**; the
  `design-direction-save`, `design-direction-capture` and `design-direction-restore` abilities report
  `active_cleared: true`.
- There is no restore control on the page. Restoring an earlier revision is the
  `stonewright/design-direction-restore` ability; in `production-safe` mode it needs a confirmation
  token for that direction and revision.

## What agents receive

While a direction is active, compact `stonewright-task-start` returns `context.design_direction_ref`
(name, slug, id and a contract hash prefix) with `required_actions: read_design_direction_brief`, and
the connect-time server instructions name the same direction on one line. The full contract stays
behind `stonewright-design-direction-brief`, which needs the task context token from
`stonewright-task-start`; `design-direction-list` and `design-direction-get` do not. Deactivating the
direction removes the pointer and the line.
