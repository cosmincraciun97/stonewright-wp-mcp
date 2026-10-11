# Troubleshoot

**Stonewright → Troubleshoot** (marked EXP; a page of the Setup group, not one of Setup's tabs)
diagnoses why an AI client cannot reach this WordPress site. Setup's own tabs are Get started,
Settings, Connections and Updates. Source: `plugin/includes/Admin/Pages/TroubleshootPage.php`,
`plugin/includes/Admin/DiagnosticsPanel.php` and `plugin/includes/Admin/SetupDiagnostics.php`.

Use it when a client never shows Stonewright tools, fails authorization, or
cannot reach the site. It probes the site the way a client does and points at
what to fix. It does **not** replace a live client restart.

## The page

One **Connection checks** card holds the form, the run button (**Run diagnostics**, the
page's one primary action, in the card header above the results), and **Copy report
for support**. Under the form a one-line summary says what the report adds up to in
words ("1 problem and 1 warning to look at", or "No problems or warnings"). A run that
finished with nothing to look at says "No problems or warnings." even though `info`
rows, which every run has (the endpoint, the transport, the connection), are listed
with it. "So far" appears only before the first run, while checks still read "Not
run yet". The checks that need attention (problems, then warnings) are one table with
the result as a word and an icon, the cause, the remedy and one action; a row never
prints the same sentence as both cause and remedy. The other checks (`info`,
`skipped`) and the checks that passed are folded into two disclosures that state
their counts. Below the card sit
**MCP runtime compatibility** and **Elementor provider discovery**. While a run is
busy the results region is `aria-busy` and shows placeholders; a request that fails
shows a notice that stays until the next run. The page script is
`assets/admin/pages/troubleshoot.js` and the stylesheet `pages/troubleshoot.css`.

## How it runs

1. Pick **How do you connect?**
   - **Not sure** — safe discovery that recommends a method. It does not guess
     credentials.
   - **OAuth** (`oauth-http`) — live MCP loopback (`initialize` →
     `notifications/initialized` → `tools/list` → `task-start` with
     `serverInfo.name` `Stonewright`), WAF-style 403/406 detection,
     User-Agent bot-filter probes, and OAuth dynamic registration.
   - **Application Password** (`application-password-stdio`) — local companion
     checks for Application Password stdio.
   - **Local companion** (`stdio`) — skips the HTTP loopback and reports
     whether a companion URL is configured.
2. Click **Run diagnostics**.
3. With JavaScript, the request posts to `admin-ajax.php`
   (`action=stonewright_run_diagnostics`) and paints the result tables in place. The
   button shows a busy state (`aria-busy`) and the page does not reload.
4. Without JavaScript, the form posts to `admin-post.php` and redirects back
   with `?stonewright_diagnostics=1`.

Checks run as a dependency-ordered graph. Failed prerequisites mark dependents
`skipped`; they do not invent secondary failures. A skipped row is shown under its
own name and says what it needed in words ("Skipped: needs Stonewright abilities and
MCP runtime to pass first."), never a check id. The first checks are the
**Domain lock** and **Stonewright abilities**: the MCP runtime, registration, tool
surface, connection, OAuth challenge and OAuth dynamic registration checks all need
**Stonewright abilities** to pass, so with Stonewright off they are skipped and the
page shows the one cause. The live handshake probe, the WAF check and the bot filter
check still run, because they show how the site answers a client. `info` checks (configured URL,
pending handshake) are listed separately and are not counted as successful
checks. Canonical `/mcp/stonewright` and OAuth `/mcp/stonewright-oauth` are
separate route and registration checks; an OAuth-only catalog does not pass
the canonical route. “Configuration was verified; the connection has not been
tested” stays `info`. Only the live handshake probe records a timestamped pass
or fail; an OAuth HTTP 401 is not a successful initialize. Problems and
warnings show evidence, remedy, a safe action, and copyable support text. An
MCP runtime conflict is a Problem with `ready:false`; do not disable unrelated
business plugins as the standard remediation.

**Domain lock.** The row names the address Stonewright is locked to and the address the site
has now. On a mismatch it is a Problem with the remedy Setup already offers, **Review and rebind
this site** or **Restore prior domain binding**, and a button that opens the Domain lock card.
**Stonewright abilities** reads the effective state (the switch, the domain lock, a failed bundled
library, a host policy), not only the stored switch, so it never says Passed while the lock
blocks the abilities. The Setup preflight and **Verify connection** say the same thing: the
preflight has its own **Domain lock** row and its abilities and tool surface rows fail, and the
Verify connection advice names the lock instead of telling you to enable abilities.

**Application Passwords.** When they are off the row names the cause: this WordPress has none,
the site is plain HTTP and not a local environment (add `WP_ENVIRONMENT_TYPE` set to `local`, or use
HTTPS), a filter or setting turns them off although the site qualifies
(`wp_is_application_passwords_available`), or they are off for the current user only
(`wp_is_application_passwords_available_for_user`).

**MCP server registration** and **OAuth MCP server registration.** Registration happens when the
REST routes start, which a page request does not do on its own, so the checks start the REST
server first and then read what Stonewright recorded: registered, blocked or failed with its
reason. If the REST routes cannot be started in that request the row stays `info` and says it
could not be checked here and that the route checks and the connection probe cover it.

The OAuth registration diagnostic sends valid RFC 7591 metadata, requires HTTP
`201` plus a valid response shape, creates an explicitly ephemeral client, and
deletes it before responding. Invalid JSON, `400`, `401`, `403`, `429`, `5xx`,
timeout, and cleanup failure are never success.

Before the first run, live-probe checks say **Not run yet — click Run
diagnostics** and sit in the folded **other checks** group; the summary does not
claim that everything passed. After a run, the page scrolls to the first check
that needs attention (smoothly only when the visitor has not asked for reduced
motion) and announces that the checks finished.

Each run's result is stored in `stonewright_diagnostics_last` (autoload off). A plain
reload of the page does not show it: it recomputes the checks live, without the probes, and
the method list goes back to **Not sure**. The stored report is shown only on the page a run
without JavaScript returns to (`?stonewright_diagnostics=1`). Checks use
`ok` / `info` / `warning` / `problem` / `skipped` statuses, shown as the words
Passed, Info, Warning, Problem and Skipped with an icon. **Copy report for support**
copies a plaintext report with the layer's copy button: its icon turns into a check and
**Copied** appears beside it, or **Press Ctrl+C** when the clipboard is blocked. The report lists
the counts, the versions, the correlation id and, for each check, its status, id, name and
summary, plus the remedy of a problem or warning. Text that looks like a credential (Bearer or
Basic values, passwords, tokens, keys, Application Passwords, passwords in addresses) and the
install path are removed from it. Optional **What do you see in your
AI client?** only changes the help copy; it does not change the probe.

The footer reports plugin SemVer and the companion HTTP contract version. It
does not claim a contract mismatch when both share major `1`.

Live passwords, authorization headers, and Application Passwords never appear
in the cards or the copied report.

## MCP adapter conflicts with other plugins

Several plugins bundle their own copy of the WordPress MCP Adapter (`wordpress/mcp-adapter`), for
example SEO and e-commerce plugins that offer an MCP server of their own. PHP can hold only one
copy of the adapter in a request, so when another plugin loads its copy before Stonewright does,
that copy is the one in use.

**What Stonewright does.** It checks the loaded copy by what it offers, not by who shipped it.
A copy of MCP Adapter 0.5.0 or newer that has the server API Stonewright calls (`instance()` and
the 13-argument `create_server()`) and the classes it builds a server from is used as it is, with
or without a Jetpack Autoloader manifest. Stonewright registers its `stonewright` and
`stonewright-oauth` servers through it, and the other plugin's own MCP server keeps working.
Rank Math SEO 1.0.280, which bundles MCP Adapter 0.5.0, is such a case. Stonewright never changes
or disables the other plugin.

**What you see.**

- *A working copy from another plugin.* Nothing is wrong. **MCP runtime** passes and its summary
  says which plugin owns the adapter and the version, for example "The MCP adapter in use is the
  copy bundled with Rank Math SEO (version 0.5.0)". **MCP runtime compatibility** on Troubleshoot
  shows the same as information. When only the adapter class itself came from the other plugin and
  the rest of the adapter came from Stonewright's copy, the card says the family is mixed; that
  works while both copies agree on the server API, which is checked.
- *A copy Stonewright cannot use* (older than 0.5.0, a different server API, or parts missing).
  The route `/wp-json/mcp/stonewright` cannot be served, so no MCP client can connect, although
  abilities still run inside WordPress. An error notice on every Stonewright screen, a **MCP
  route unavailable** card on Troubleshoot, a failed **MCP runtime** check in the connection checks,
  `mcp_runtime` in `stonewright-site-capabilities` and in `stonewright-task-start`, and an HTTP 503
  answer on both MCP routes (instead of a missing route) all name the plugin that owns the loaded
  copy, its version, the reason and what to do.

**What to do when the route is blocked.**

1. Read the plugin name and version in the notice or the **MCP runtime compatibility** card.
2. Update that plugin to a release that bundles MCP Adapter 0.5.0 or newer. If it ships none yet,
   deactivate it until it does; ask its author to load the adapter through the Jetpack Autoloader,
   which lets the newest copy win.
3. Reload WordPress and run diagnostics again. The notice and the 503 answer go away on their own
   once a usable copy is loaded.

Do not disable plugins you need only to silence the notice: a copy that works is accepted, and
the notice appears only while no usable copy is loaded.

## Compact tool surface

Choosing the **full** MCP surface on purpose reports `info` ("Full surface
selected — N tools. Compact profiles reduce agent token cost."). That is not a
problem. The check warns only when a compact stored preference
(`bootstrap` / `essential`) has drifted above the compact tool budget.

## Bot / WAF user-agent filter

When you run HTTP diagnostics, Stonewright loopbacks `GET` to the MCP endpoint
with User-Agents `python-httpx`, `node`, and `Go-http-client`. Any HTTP 403 or
406 is a warning. The card includes a generic hosting-ticket block (site URL
and endpoint only) and a **Copy hosting request** button you can paste to hosting
support. Ask them to allow those User-Agents, or to allow the `/wp-json/mcp/`
path.

## OAuth dynamic registration

The same HTTP run `POST`s to the local OAuth dynamic-registration endpoint
(`/wp-json/stonewright/v1/oauth/register`) with valid RFC 7591 metadata. Success
requires HTTP `201` plus a valid response shape. The diagnostic creates an
explicitly ephemeral client and deletes it before responding. Invalid JSON,
`400`, `401`, `403`, `429`, `5xx`, timeout, and cleanup failure are never
success. A `finally` cleanup and scheduled garbage collection cover lost
diagnostic responses.

See also [Configuration](configuration.md) (**Verify connection** vs this
panel) and [Connect clients](connect-clients.md).
