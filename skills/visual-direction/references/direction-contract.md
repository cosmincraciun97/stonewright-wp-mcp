# Direction contract

A design direction is a stored, versioned record of how a site should look. It
is the thing a later session reads instead of guessing, and the thing a diff is
measured against.

## Abilities

| Ability | Use |
| --- | --- |
| `stonewright-design-direction-get` | Read the active direction before planning anything visual. |
| `stonewright-design-direction-list` | Show the user what already exists before proposing a new one. |
| `stonewright-design-direction-capture` | Turn evidence and stated intent into a draft direction. |
| `stonewright-design-direction-save` | Persist a draft or an edit. Creates a new revision; it does not overwrite history. |
| `stonewright-design-direction-activate` | Promote a revision to active. Review with the user first. |
| `stonewright-design-direction-restore` | Roll back to an earlier revision when a change went wrong. |
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

`sync-apply` writes global styles, so it takes a backup snapshot and, in
`production-safe` mode, a confirmation token issued by
`stonewright-security-issue-confirmation-token`. Apply the subset the user
approved — not the whole plan because the whole plan was easier to pass along.

Kit changes are site-wide. A global token edit made to satisfy one section is
almost always the wrong fix; adjust the section instead, or say plainly that
the direction itself needs to change.

## Restore

`stonewright-design-direction-restore` takes a revision id and makes it active
again. Use it as soon as a direction change turns out to be wrong — it is
cheaper and more honest than hand-editing values back one at a time. After a
restore, re-run the kit sync plan: the kit does not roll back on its own.
