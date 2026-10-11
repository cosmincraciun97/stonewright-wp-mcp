---
name: stonewright-rescue
description: >
  Use when a Stonewright response carries pending_incident or the error
  stonewright_rescue_write_rolled_back or stonewright_rescue_rollback_failed,
  when a change left a WordPress site failing to load, when a notice says
  recent changes are not verified, or when the user asks what changed on the
  site or wants a change undone or redone.
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
reported back), each with the rollback it would run, and the `recent` changes.

## Read the signal

| You see | It means | Do |
|---|---|---|
| `stonewright_rescue_write_rolled_back` | The site stopped loading and the change was undone | Read `site_status`. `healthy`: the site loads again, so change the approach before any retry. `still_failing` or `unknown`: stop and report; the fault may not come from this change |
| `stonewright_rescue_rollback_failed` | The site stopped loading and the automatic rollback failed | Roll back with the `incident_id` from the error, below |
| `pending_incident` on any response | An incident is open | Call `stonewright-rescue-status`, then roll it back before any other write |
| A `notices` line saying the probe is unavailable | The host cannot call itself, so changes are recorded but not verified | Check the page, wp-admin, and the REST index by other means, then `recheck` |
| `stonewright_rescue_in_progress` | Another caller is already rolling this change set back | Wait a minute, then call `stonewright-rescue-status`; do not start a second rollback |
| `stonewright_rescue_undo_reverted` | The undo of a verified change would have broken the site, so it was put back and the change is still in effect | Report it to the user with the evidence in the message. Do not retry the undo; the older state fails on this site |
| `stonewright_rescue_undo_capture_failed` | The current state could not be saved first, so the undo was refused and nothing changed | Report it to the user. Do not work around it |
| `stonewright_rescue_undo_revert_failed` | The undo broke the site and the earlier state could not be put back, so the site may be failing and an incident is open | Report it to the user with the evidence in the message. Point to safe mode at **Stonewright > Activity > Rescue**. Do not retry |
| `stonewright_rescue_approval_required` | A verified change to code (theme file, custom code, sandbox file, Customizer CSS) can be undone only by an administrator | Show the user the change and `approval_url`, ask them to use **Stonewright > Activity > Rescue**, then stop. Do not retry |

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

## Undo a verified change

`stonewright-rescue-rollback` also rolls back a change that passed its health
check, when the user asks for it (the journal keeps the last 50). Pass the
`change_set_id` of the earlier write as `incident_id` and follow the steps
above, dry run first. A verified change to code is the exception: the ability
answers `stonewright_rescue_approval_required`, and only the administrator can
roll it back, on the Rescue page. An undo of a verified change saves the current
state and probes the site before and after; when the undo makes a working site
fail, it is put back and the answer is `stonewright_rescue_undo_reverted`.

## Find and undo a change from the history

Stonewright keeps a history of changes to posts, Elementor documents, options,
menus, widgets, theme and custom code, users, comments, media, WooCommerce, skills,
design directions and memory. Use it when the user asks what changed, or wants one
change undone or redone. It needs an administrator account, and it is separate from
the incident flow above.

1. **Find it.** Call `stonewright-change-history-list`. Filter with `resource` (a
   post id, an option name, a file path), `family`, `ability`, `status`, `from` and
   `to`, or `restorable` true. A row is short: `change_id`, `time`, `summary`,
   `status`, `restorable` and why not, `parent_id` and `children`. Page with `page`
   and `per_page`.
2. **Read the diff.** Call `stonewright-change-diff-get` with the `change_id`. It
   returns the diff (secrets masked, capped; `truncated` says when lines were left
   out) and a `plan`. Read `plan.drift` (the item was edited after the change),
   `plan.newer_changes`, `plan.restorable` and `plan.approval_required`. When
   `plan.available` is false, tell the user `plan.error_code` and stop. It never
   returns stored content.
3. **Plan with a dry run.** Call `stonewright-change-rollback` with `change_id` and
   `dry_run` true, and tell the user what it would restore. Stop and ask before
   going on when `drift` is true or newer changes exist: the undo overwrites them.
4. **Run it** with `dry_run` false. Add `force_drift` true only when the user agreed
   to overwrite later edits, and `expected_current_sha256` from the plan to undo
   exactly the state the user saw. In production-safe mode call
   `stonewright-security-issue-confirmation-token` with `ability`
   `stonewright/change-rollback` and `args` set to the `confirmation_args` that the
   dry run returned, unchanged, then pass the token as `confirmation_token`. A token
   fits one call.
5. **Check the result.** It has `rollback_change_id`; to redo, call
   `stonewright-change-rollback` with that id. The result must say `site_status`
   `healthy`; anything else: stop and report. `stonewright_change_rollback_reverted`
   means the undo made the site fail and was put back: report it, do not retry.

**Code needs the administrator.** A change to code (theme file, custom code,
sandbox file, Customizer CSS) is never undone by an agent call, because every code
write in Stonewright stops at the approval of a person at wp-admin, and an agent
call is not that person. The answer is `stonewright_rescue_approval_required` with
an `approval_url`: show it to the user, ask an administrator to open
**Stonewright > Activity > Changes**, open the change and press **Undo**, then stop.
Do not retry, and do not use a token, `stonewright-php-execute` or file writes to get
around it. `wp stonewright changes rollback` is not the administrator either.

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
  and point to **Stonewright > Activity > Rescue** in wp-admin; do not improvise one.

## When the site is down

The abilities need the site to answer. When wp-admin does not load, the user can
open the Stonewright rescue link from the WordPress recovery mode email, sign in
as usual, and use Stonewright > Activity > Rescue in safe mode; or run
`wp stonewright rescue status` on the server.
