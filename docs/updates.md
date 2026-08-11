# Updating Stonewright

The WordPress plugin and Node companion are separate release artifacts. Use
the same Stonewright version for both whenever local stdio connects to a site
with the plugin.

Local stdio means the AI client starts the companion on the user's computer and
communicates with that process through standard input/output. Remote HTTP
connects directly to the WordPress plugin over HTTPS, so there is no local
companion to update. Direct mode always uses the companion.

## Which component needs an update?

| Connection | Update the plugin | Update the companion |
|---|---:|---:|
| Local stdio to a site with Stonewright | Yes | Yes |
| Remote Streamable HTTP to the plugin | Yes | No local companion is running |
| Pluginless Direct mode | No plugin is installed | Yes |

Update when GitHub Releases or WordPress reports a newer version, Setup reports
a version mismatch or missing tools, or a release fixes a security,
activation, packaging, or compatibility issue that affects the site.

## Update the WordPress plugin

1. Take a normal site backup.
2. Download `stonewright-VERSION.zip` from the current GitHub release.
3. In WordPress, open **Dashboard → Updates**. Use the normal update when it is
   offered. Otherwise open **Plugins → Add Plugin → Upload Plugin**, upload the
   ZIP, and choose **Replace current with uploaded**.
4. Return to **Stonewright → Setup** and run **Verify connection**.

An update runs schema migrations in place. It does not delete or reset existing
memory, user-created skills, audit history, content, Elementor data, store data,
or Stonewright settings.

## Update the local companion

In **Stonewright → Connect → Keep Stonewright current**, click **Check latest
companion**. The result compares:

- the installed WordPress plugin;
- the latest trusted GitHub release;
- the optional configured HTTP bridge, when WordPress can reach it.

This explicit check refreshes the official release immediately. Automatic
background checks remain cached to avoid unnecessary GitHub requests.

Local stdio runs inside the AI client, so WordPress cannot inspect or replace
that process directly. Use **Copy update prompt** to hand a credential-free
update request to the agent, or use **Download official companion** and verify
the linked SHA-256 manifest.

1. Open the private MCP configuration for the AI client.
2. Replace the old `stonewright-companion-VERSION.tgz` release URL with the URL
   from the current release. Keep credentials private; never paste them into an
   issue, chat, repository, or command saved in shell history.
3. Fully restart the AI client so the old companion process and cached tool
   list are gone.
4. Call `stonewright-task-start`, then
   `stonewright-setup-profile` and `stonewright-wordpress-mcp-status`. Confirm
   `companion_version` matches the installed plugin when using stdio,
   `expected_companion_package` is current, and
   `refresh_required_tool_names` is empty.

For alias-based stdio installs, prefer the versioned repair command over
editing a generic MCP block:

```bash
npx -y --package https://github.com/cosmincraciun97/stonewright-wp-mcp/releases/download/vVERSION/stonewright-companion-VERSION.tgz stonewright connect repair <alias> --client <client> --mode plugin-only
```

This reuses the existing credential reference, refreshes only the named client
entry, and makes the alias authoritative over stale inherited WordPress
environment values. Restart the client, then run `connect verify`; another
alias or `active_mode=direct` is a failed plugin-mode update, not success.

Direct mode keeps its private state under `~/.stonewright/`. Replacing the
companion package does not reset its memory, user-created skills, site
configuration, backups, or audit history.

## Fresh install versus update

A genuinely new plugin installation creates the database schema with:

- zero memory rows;
- zero user-created skills;
- zero audit events.

Generic built-in skills and native rules are product assets, not site memory or
customer data, so they are available immediately. In Direct mode, a new
`~/.stonewright` state directory likewise starts with no user memory,
user-created skills, or audit events; packaged generic built-ins remain
available.

The first real operation may create an audit event. Setup or learning actions
create state only after the user or agent performs them.

## Credential rules

- Prefer OAuth for supported remote HTTP clients.
- Keep Application Passwords in private client configuration or a
  permission-restricted `~/.stonewright/sites.json`.
- The wp-admin client snippet can contain a newly generated one-time
  Application Password for direct private saving. The paste-to-agent prompt is
  always credential-free and uses placeholders.
- Never store secrets in memory, skills, audit examples, screenshots,
  changelogs, release notes, public issues, or tracked files.
