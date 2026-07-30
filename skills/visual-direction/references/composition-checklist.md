# Composition checklist

Things worth looking at before you show work to a user. Every item here is a
recommendation, not a blocker. A build is stopped by the quality report, by
missing breakpoint evidence, or by the user's stated direction — never by an
item on this list on its own.

When an item looks wrong, say what you saw, say what you would change, and let
the user decide. "The section reads flat because every element is the same
weight" is useful. "This violates hierarchy" is not.

## Contrast

- Text over an image or a tinted surface still has to be readable at the
  smallest targeted breakpoint, not only at desktop.
- Accent colors should stay accents. When everything is emphasised, nothing is.
- Check contrast against the rendered result, not against the hex values —
  overlays, gradients, and opacity change the answer.

## Hierarchy

- One primary message per section, and one primary action.
- Size, weight, colour, and space are four ways to say "this matters more".
  Two are usually enough; four at once reads as noise.
- Heading levels should follow the document, not the visual size. A styled
  smaller heading is fine; skipping a level to get a size is not.

## Rhythm

- Spacing between sections should come from the direction's rhythm unit, not
  from whatever number closed the visual gap.
- Repeated blocks (cards, list rows, logos) want equal internal padding. Eyes
  catch a 4px inconsistency faster than a wrong colour.
- Vertical rhythm survives breakpoint changes only if it is expressed in the
  same unit at each one.

## Intentional variation

- A page of identical sections reads as a template. Vary alignment, media
  position, or background weight on purpose.
- Variation should be legible as a decision: alternating, grouping, or
  escalating. Random variation reads as a mistake.
- Keep the varying axis small. Changing background *and* alignment *and* media
  ratio *and* column count in one step usually looks broken rather than varied.

## Content density

- Line length in the 45–85 character range is a comfortable default for body
  copy. Wide full-bleed text is the most common density problem.
- Real content, not placeholder length. A card grid that only works with
  seven-word titles will break the first time an editor writes a sentence.
- Empty states and overflow states are part of the design. Say what happens
  when a field is blank or a list is long.

## Motion restraint

- Motion should explain a change of state, not announce that the page exists.
- Respect `prefers-reduced-motion`. If the direction asks for motion, it also
  has to say what the reduced variant does.
- Scroll-triggered animation on every section is the fastest way to make a
  fast site feel slow.

## Reporting

Group findings as *blocking* (evidence or gate failures, with the failing
check named) and *suggested* (this list). Keep the suggested items short and
attach the reason. If the user declines a suggestion, record it — a preference
stated once should not be re-litigated in the next session.
