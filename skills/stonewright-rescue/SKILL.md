---
name: stonewright-rescue
description: >
  Use when a Stonewright response carries pending_incident or the error
  stonewright_rescue_write_rolled_back or stonewright_rescue_rollback_failed,
  when a change left a WordPress site failing to load, or when a notice says
  recent changes are not verified.
---

# Stonewright Rescue

Rescue records every risky write before it runs, probes the site afterwards, and
rolls the change back when the site stops loading. This skill says what to do
when Rescue reports a problem. Rescue covers post, option, theme-file, plugin,
sandbox, and custom-code writes made through Stonewright abilities.

## First call

Call `stonewright-rescue-status`. It is read-only, runs no probe, and changes
nothing. It lists `open_incidents` (a rollback that failed, or a PHP fatal
recorded after a change), `unconfirmed` changes (made, but no health check
reported back), and the latest changes, each with the rollback it would run.

## Read the signal

| You see | It means | Do |
|---|---|---|
| `stonewright_rescue_write_rolled_back` | The site stopped loading and the change was undone | Read `site_status`. `healthy`: the site loads again, so change the approach before any retry. `still_failing` or `unknown`: stop and report; the fault may not come from this change |
| `stonewright_rescue_rollback_failed` | The site stopped loading and the automatic rollback failed | Roll back with the `incident_id` from the error, below |
| `pending_incident` on any response | An incident is open | Call `stonewright-rescue-status`, then roll it back before any other write |
| A `notices` line saying the probe is unavailable | The host cannot call itself, so changes are recorded but not verified | Check the page, wp-admin, and the REST index by other means, then `recheck` |
| `stonewright_rescue_in_progress` | Another caller is already rolling this change set back | Wait a minute, then call `stonewright-rescue-status`; do not start a second rollback |

## Roll back

1. Call `stonewright-rescue-rollback` with `incident_id` and `dry_run` true. It
   returns the plan in `recipe.plan` and changes nothing. Tell the user what it
   will restore, and how old the change is (`age_seconds`). When `warnings` is
   present, a newer change to the same item exists (`newer_changes`) and the
   rollback would overwrite it: stop and ask the user before going on.
2. In production-safe mode, call `stonewright-security-issue-confirmation-token`
   with `ability` `stonewright/rescue-rollback` and `args` holding exactly the
   arguments of the call that follows. A token fits one call.
3. Call `stonewright-rescue-rollback` with `incident_id` (and
   `confirmation_token`). It runs the recorded recipe, probes the site, and
   records the outcome on the change set and in the Audit Log.
4. The result must say `site_status` `healthy`. Anything else: stop and report.

## After a fix by hand

When a person undid the change, call `stonewright-rescue-rollback` with
`action` `recheck`. It probes again and closes the incident when the site loads.
It never changes the site. In production-safe mode it needs a confirmation
token, even with `dry_run`, because it can close the incident.

## Forbidden

- Do not retry the change that failed until the incident is closed.
- Do not undo a change with `stonewright-php-execute`, WP-CLI, or file writes
  when a rollback is on offer. The ability records what it did; a manual edit
  leaves the incident open.
- Do not skip the confirmation token in production-safe mode.
- When `recipe.available` is false, no automatic rollback exists. Tell the user
  and point to **Stonewright > Rescue** in wp-admin; do not improvise one.

## When the site is down

The abilities need the site to answer. When wp-admin does not load, the user can
open the Stonewright rescue link from the WordPress recovery mode email, sign in
as usual, and use Stonewright > Rescue in safe mode; or run
`wp stonewright rescue status` on the server.
