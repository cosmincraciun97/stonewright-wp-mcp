# Troubleshoot

**Stonewright → Connect → Troubleshoot** diagnoses why an AI client cannot
reach this WordPress site. The same panel also sits at the bottom of
**Setup**. Source: `plugin/includes/Admin/Pages/TroubleshootPage.php` and
`plugin/includes/Admin/DiagnosticsPanel.php`.

Use it when a client never shows Stonewright tools, fails authorization, or
cannot reach the site. It probes the site the way a client does and points at
what to fix. It does **not** replace a live client restart.

## How it runs

1. Pick **How do you connect?**
   - **Not sure (check both)** — HTTP loopback plus local companion checks.
   - **Remote Streamable HTTP / OAuth** — live MCP loopback (`initialize` →
     `tools/list` → `task-start`), WAF-style 403/406 detection, User-Agent
     bot-filter probes, and OAuth dynamic registration.
   - **Local companion (stdio)** — skips the HTTP loopback and reports whether
     a companion URL is configured.
2. Click **Run diagnostics**.
3. With JavaScript, the request posts to `admin-ajax.php`
   (`action=stonewright_run_diagnostics`) and paints result cards in place. The
   button shows a loading spinner (`aria-busy`) and the page does not reload.
4. Without JavaScript, the form posts to `admin-post.php` and redirects back
   with `?stonewright_diagnostics=1`.

Before the first run, live-probe cards say **Not run yet — click Run
diagnostics**. After a run, the **N Problems** pill scrolls to the first
non-pass card.

Results are stored in `stonewright_diagnostics_last` (autoload off). Cards use
`ok` / `warn` / `error` / `info` statuses. Problem and warning pills appear
when those counts are greater than zero. **Copy report for support** copies a
plaintext report (no secrets). On non-HTTPS pages, if the clipboard API fails,
the panel falls back to `document.execCommand('copy')`, then a readonly
textarea modal with **Press Ctrl/Cmd+C**. Optional **What do you see in your
AI client?** only changes the help copy; it does not change the probe.

The footer reports plugin SemVer and the companion HTTP contract version. It
does not claim a contract mismatch when both share major `1`.

Live passwords, authorization headers, and Application Passwords never appear
in the cards or the copied report.

## Compact tool surface

Choosing the **full** MCP surface on purpose reports `info` ("Full surface
selected — N tools. Compact profiles reduce agent token cost."). That is not a
problem. The check warns only when a compact stored preference
(`bootstrap` / `essential`) has drifted above the compact tool budget.

## Bot / WAF user-agent filter

When you run HTTP diagnostics, Stonewright loopbacks `GET` to the MCP endpoint
with User-Agents `python-httpx`, `node`, and `Go-http-client`. Any HTTP 403 or
406 is a warning. The card includes a generic hosting-ticket block (site URL
and endpoint only) and a **Copy ticket** button you can paste to hosting
support. Ask them to allow those User-Agents, or to allow the `/wp-json/mcp/`
path.

## OAuth dynamic registration

The same HTTP run `POST`s to the local OAuth dynamic-registration endpoint
(`/wp-json/stonewright/v1/oauth/register`) with a 5 second timeout. Timeout or
connection refusal is a warning and shows the exact error string. An HTTP
response from the endpoint (including 4xx validation) means the route is
reachable.

See also [Configuration](configuration.md) (**Verify connection** vs this
panel) and [Connect clients](connect-clients.md).
