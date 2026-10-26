# Stonewright on busy or constrained sites

Some sites run on a host that limits each account's processes, memory and CPU, and
some are served almost entirely from a page cache that a save can empty. A busy
hour on such a site leaves little room for anything that does extra work, and an
agent that saves one page a dozen times can be enough to tip it over. Stonewright
keeps its own cost small and gives the site owner four guard rails for the busy
hours. By default they warn. Only the freeze and the opt-in pacing mode refuse, and
only an administrator in wp-admin turns them on, lifts them or loosens them.

## What the plugin costs per request

A request that has nothing to do with Stonewright (a page of the front end, the
`admin-ajax.php` heartbeat, a cron run, a REST call to another namespace) costs the
plugin the options WordPress already loads together (autoload) and no database query
or file scan of its own:

- The database tables are installed or repaired on activation, on a version change,
  in the daily job, and when a write fails because a column is missing. They are not
  checked on every request. An install runs under a lock, one process at a time, and
  a failed install is not tried again for ten minutes.
- Lesson migrations run on an update and in the daily job, with an attempt limit, never on a page request.
- The MCP compatibility report, the verdicts of the generated-widget scan and the
  position of the change journal file are remembered, so none of them is worked out again on every request.
- The rescue helper is compared with the bundled copy at most every twelve hours, or when its file changes or the plugin is updated.

## Site pressure

Optional work asks how hard the site is working before it starts. The answer is one of
three levels:

| Level | What it means |
|---|---|
| `normal` | The site answers quickly. Everything runs. |
| `elevated` | The database is slower than usual. Lesson migrations and the rescue helper check wait, and the health probe checks the front page alone. |
| `critical` | The database is very slow or memory is nearly used up. Schema repairs and the change journal rewrite wait as well, and any health probe is one short request. |

The level comes from one `SELECT 1`, timed, together with the machine's load and the
memory in use. It is measured only when something is about to do optional work, never
on a plain front-end request, and the measurement is reused for 30 seconds. A
measurement that fails counts as `normal`. The database time and the load describe the
site and are shared through the stored measurement; the memory describes one request,
so each request reads its own and one heavy request never holds work back from the others.
The memory of a request is a gate for that request's own optional work only: the
`site_pressure` an agent is told comes from the database time and the load alone, so a
request that just did a heavy write and is close to its own memory limit never makes
the agent think the site is under load.

| Reading | Threshold |
|---|---|
| Database round trip | 250 ms is `elevated`, 1 second is `critical` |
| Memory in use | 85% of the PHP memory limit is `critical` |
| Load per core | 4 is `elevated`, and only together with a database slower than 100 ms; load alone never makes the level `critical` |

The thresholds can be changed with the filter `stonewright_site_pressure_thresholds`.

What waits under pressure:

- `elevated` or `critical`: the lesson migrations, the rescue helper check, and the
  second and third leg of the site-wide health probe (the front page alone is asked).
- `critical` only: a schema repair (the table backs off for a minute), the rewrite of
  the change journal file, and the health probe's extra requests (any probe is one
  short request).

An agent sees the level. While the site is not `normal`, every ability response carries
`site_pressure` with the `level`, the `db_ms` that was measured and a `hint`: batch
writes into one apply per post, and avoid Elementor saves and plugin changes until
the site recovers.

## Write freeze

An administrator can pause agent writes until a chosen time, for a launch, a traffic
peak or an incident. In wp-admin, under **Stonewright > Setup > Settings > Busy sites**,
set **Freeze writes until** (a date and time in the site's timezone) and a **Reason**.
Leave the time empty to lift the freeze. A time that is in the past is refused.

While the freeze is on:

- Abilities that change the site answer `stonewright_write_freeze` (HTTP status 423)
  with the end time and the reason. The agent shows the settings link and stops.
- Reads, `stonewright/task-start`, the rollbacks and restores of the change history
  (`rescue-rollback`, `change-rollback`, `change-restore`), the confirmation token a
  production-safe rollback needs, the incident record, and the records of what the
  agent learned keep working, so a change can still be taken back during a freeze.
  A restore that is not tied to the change history (`post-revision-restore`,
  `theme-backup-restore`) is a write like any other and stays frozen.
- Only a person in wp-admin can lift the freeze or end it sooner. A request with an
  Application Password or an OAuth bearer that tries is not stored: the freeze stays as it was. Making the freeze longer
  stays open to anyone with `manage_options`, like every other setting that
  tightens a limit.

A freeze that has passed its end time is inactive; nothing needs to lift it.

## Elementor write pacing

Every save of an Elementor document is counted per post in a ten-minute window,
whoever makes it: an agent through a Stonewright writer or a person in the editor.
The editor's autosaves are not saves of the post and are not counted.
What the count does depends on **Elementor write pacing** under
**Stonewright > Setup > Settings > Busy sites**:

| Setting | Behaviour |
|---|---|
| Warn (default) | The response of the request that made the third save of a post in ten minutes carries `write_pacing`: the posts, how many saves each had, and the hint to consolidate the remaining changes into one `elementor-v3-batch-mutate` apply. Nothing is refused. |
| Strict | As Warn, and the sixth save of one post in ten minutes is refused with `stonewright_write_pacing` (HTTP status 429, with `retry_after` in seconds) until the window has passed. |
| Off | Nothing is counted. |

The limits can be changed with the filter `stonewright_elementor_write_pacing_limits`
(`window`, `warn_at`, `refuse_at`). Only a person in wp-admin makes the setting looser.

## Cache-purge advisories

Stonewright does not change another plugin's cache rules and does not suppress its
hooks while it writes. It reads the rules and tells the agent. With LiteSpeed Cache
active, these Auto Purge Rules produce an advisory:

| LiteSpeed Cache setting | Effect |
|---|---|
| Purge › Auto Purge Rules: All pages, and Pages | Every page is purged on each post or page save |
| Purge › Purge All On Upgrade | The whole cache is purged when a plugin or theme is updated |

The advisory travels in three places: `site.cache_purge` in the answer of
`stonewright/task-start` and `cache_purge` in the answer of `stonewright/site-pulse`; a
`cache_purge` line among the `notices` of the responses after an Elementor, kit or
template write, once per hour; and `cache_purge` in the answers of the plugin and
sandbox activate and deactivate abilities. The site owner decides whether to change the rule; the
agent batches its changes and avoids saves during traffic peaks.

## The health probe under pressure

The probe that checks the site after a write asks for the front page, a wp-admin screen
and the REST index. On a site that is not `normal` a site-wide check asks for the
front page alone, and on a `critical` site any probe is one short request.
The probe of a page that was just written keeps its own request. See
[Rescue](rescue.md#the-health-probe).

## Removing Stonewright on a constrained host

See [Updating the plugin and companion](updates.md) for removing the plugin.
