# Overview

**Stonewright → Overview** is the page the Stonewright menu opens. It answers three
questions, in this order: is it working, what needs me, and what happened. It is
read-only; every action on it links to the page that does the work.

Sources:
- `plugin/includes/Admin/Pages/StatusPage.php`
- `plugin/includes/Admin/OverviewData.php`

The page keeps its original address (`admin.php?page=stonewright-status`), so every
bookmark and link that pointed at the old Dashboard still opens it.

## Is it working

The page header carries the state of AI abilities (on, off, or blocked with the
reason on the Setup page), the mode and the plugin version. Under it, one band shows:

| Tile | Says |
| --- | --- |
| Connection | How many clients are connected (OAuth clients plus Application Passwords) and when the last client signed in |
| Mode | Development, Staging or Production-safe, and whether confirmation tokens are on |
| Tool surface | How many tools a client can call now, counted from the abilities WordPress registered, and the profile (essential, full or bootstrap) |
| Last activity | When the audit log last recorded a change, and how many changes it recorded in 14 days |
| Companion | Whether the optional local bridge has a URL set: "Not used", "Configured" with host and port, or "Needs attention". The page shows a state and never the stored URL, and it does not probe the bridge |

## What needs me

**Needs attention** lists, most urgent first, only what is true now. Each item has a
state in words (Open, Unconfirmed, Failed, Queued, Setup), a sentence, and one action:

- changes that need a rollback, and changes whose health check never reported back (Rescue);
- open incidents in the audit log;
- block changes that failed or are waiting for an editor (Block queue);
- an unverified connection: abilities are on and no client has called the site yet.

When nothing needs you, the card says so.

While setup is unfinished, **Finish setup** lists the four steps (enable AI abilities,
choose a sign-in method, connect a client, verify the connection), marks the next one,
and offers it as the one primary button on the page.

## What happened

**Recent activity** shows the last changes agents made, with the outcome as a word
(OK, Error, Blocked, Sign-in), a link to the audit log filtered to that ability, and
a 14-day sparkline. It is empty until an agent writes something.

**This site** keeps the facts the old Dashboard showed: the Site Pulse score, the
Elementor version, and the number of skills and memory entries, each linking to its
page.

## Behaviour

- Only administrators (`manage_options`) can open it.
- Nothing on the page writes. A source that cannot be read (a table that is not
  installed yet, a store that fails) reads as nothing to report instead of an error.
- At 782px and below the status band and the tables stack; no content scrolls sideways.
