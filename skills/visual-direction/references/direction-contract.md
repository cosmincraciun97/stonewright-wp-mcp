# Direction contract

A design direction is a stored, versioned record of how a site should look. It
is the thing a later session reads instead of guessing, and the thing a diff is
measured against.

## Abilities

| Ability | Use |
| --- | --- |
| `stonewright-design-direction-get` | Read the active direction before planning anything visual. |
| `stonewright-design-direction-list` | Show the user what already exists before proposing a new one. |
| `stonewright-design-direction-capture` | Turn compact Elementor kit evidence into a draft direction. Previews by default; stores a draft only with `save: true`, and refuses a slug that holds a non-draft direction unless `overwrite: true`. |
| `stonewright-design-direction-save` | Persist a draft or an edit. Creates a new revision; it does not overwrite history. Saving a not-ready revision of the active direction switches it off (`active_cleared`). |
| `stonewright-design-direction-activate` | Make a ready direction (`id`) the active one; `id` 0 clears it. Review with the user first. |
| `stonewright-design-direction-restore` | Write an earlier revision (`id`, `revision`) back as a new revision when a change went wrong. |
| `stonewright-design-direction-sync-plan` | Compute the diff between the direction and the live Elementor kit. |
| `stonewright-design-direction-sync-apply` | Write the approved part of that diff to the kit. |

## Capture

Capture records decisions, not screenshots. A useful direction states:

- **Palette** — role-named colors (surface, ink, accent, muted), not a bag of
  hex values. Say which role each existing kit color maps to.
- **Typography** — family, scale steps, weights actually used, and line height
  per step. Say what the body size is at each targeted breakpoint.
- **Spacing** — the rhythm unit and the section padding it produces.
- **Imagery** — treatment, crop behaviour, and what happens when an image is
  missing.
- **Motion** — how much, and where none is allowed.
- **Provenance** — where each value came from: measured from evidence, stated
  by the user, or inferred. Inferred values are the ones to confirm.

Anything you inferred goes in the unresolved list. Do not launder a guess into
a recorded decision by leaving the provenance blank.

## Activate

Activation changes what every later session treats as the default. Present the
diff against the currently active revision — added, changed, and removed keys —
and let the user approve it. Do not activate a direction you captured in the
same turn without showing it first.

## Sync with the Elementor kit

`sync-plan` is read-only. It reports, per kit key, the current value, the
proposed value, and where the proposal came from. Read it out loud before
proposing `sync-apply`.

`sync-apply` writes global styles, so it needs the `base_hash` that `sync-plan`
returned (it is refused when the live kit moved since the plan), takes a
backup snapshot and, in `production-safe` mode, a confirmation token issued by
`stonewright-security-issue-confirmation-token`. Apply only what the user
approved: `sync-apply` takes the whole plan and has no subset argument, so when
the user approves only part of it, change the direction, plan again and apply
that plan.

Kit changes are site-wide. A global token edit made to satisfy one section is
almost always the wrong fix; adjust the section instead, or say plainly that
the direction itself needs to change.

## Restore

`stonewright-design-direction-restore` takes a direction `id` and a `revision`
and writes that revision back as a new revision; history stays intact, and in
`production-safe` mode it needs a confirmation token. Restoring a revision that
is not ready, on the active direction, switches the direction off
(`active_cleared`). Use it as soon as a direction change turns out to be wrong —
it is cheaper and more honest than hand-editing values back one at a time. After
a restore, re-run the kit sync plan: the kit does not roll back on its own.
