# Knowledge and learning lifecycle

Stonewright never treats a web result or one successful task as trained
knowledge. Runtime research is stored site-locally as a candidate and stays out
of automatic task context until it passes a promotion gate.

## Lifecycle

1. Read the live runtime schema and fingerprint.
2. If behavior is still unclear, use official Elementor documentation.
3. Create a candidate with `stonewright/knowledge-candidate-record`.
4. Dry-run the recipe, then verify editor and logged-out frontend behavior.
5. Record successful verification against the exact runtime fingerprint.
6. Promote after two distinct successful tasks, or after explicit user approval
   with an approval note.
7. Resolve a conflicting active topic explicitly; Stonewright never silently
   replaces it.

Candidate states are `candidate`, `verified`, `approved`, `stale`, and
`rejected`. Creation may generate a site skill, but that skill is a disabled
draft. Research never creates an active skill directly.

## Required evidence

An Elementor candidate requires:

- topic, widget/control where applicable, and a concise fact or recipe;
- official source URL, lowercase SHA-256, and fetch time;
- explicit Core, Pro, and add-on version constraints where relevant;
- evidence type and confidence;
- expiry time;
- verification task IDs and exact runtime fingerprints before automatic
  promotion.

Use `stonewright/knowledge-candidates` for compact refs or one full record. Keep
candidate bodies on demand; do not inject the complete research archive into
task start.

## Invalidation and rollback

Expired candidates become stale. Elementor activation, deactivation, or update
recomputes the runtime fingerprint and stales incompatible verified knowledge,
including its linked active skill. Generic or compatible candidates remain
untouched.

Every site skill update snapshots the previous revision. Use the
`skill_rollback` action of `stonewright/knowledge-candidate-record` to restore a
known revision. Lint blocks unclear triggers, missing Elementor version ranges,
stale references, unresolved conflicts, and references to unavailable
Stonewright tools.

## Memory retrieval

Task start excludes stale and expired memory, ranks only the relevant top five
refs, and gives user instructions precedence over feedback, project,
reference, and generic entries. It returns routing metadata rather than bodies;
load the body only when the ref matches the current task. Same-topic value
disagreement is reported as a conflict instead of being silently merged.

Runtime knowledge lives in versioned WordPress tables, not in the plugin
directory, so plugin updates do not erase site learning.

The Memory admin page separates User Rules, Project Rules, Verified Repairs,
Unresolved Incidents, Audit Feedback, and Reference entries. It shows backend,
origin, visibility, lifecycle state, verification state, and last retrieval.
Legacy audit feedback is reclassified only through the explicit migration
action after the operator confirms an export; migration preserves historical
rows and never invents an active rule.

Plugin-backed Direct sessions use this site table through typed task-start and
learning routes. Pluginless Direct storage remains local and its receipt says
that it is not visible in the site Memory UI.
