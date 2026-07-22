# Security

The full security reference lives in [plugin/SECURITY.md](../plugin/SECURITY.md). This page provides additional context on the threat model and hardening recommendations.

## Summary

Stonewright runs direct WordPress automation with four operator-control layers:

1. **WordPress capabilities** — every ability checks a real capability before running.
2. **Pre-write backups** — every write that touches Elementor data or FSE styles snapshots the current state first.
3. **Confirmation tokens** — destructive operations require a short-lived, single-use token to proceed.
4. **Audit log** — every write is recorded in an append-only database table.

## Threat model

### Agent with excessive permissions

An MCP client that authenticates with an administrator account can call any ability, including destructive ones. Mitigations:

- Set `stonewright_mode` to `production-safe` to block all destructive abilities regardless of authentication level.
- Create a dedicated WordPress user with the minimum capabilities needed for the intended workflow. For read-only agents, `read` is sufficient. For content workflows, `editor` is usually enough.
- Rotate Application Passwords regularly.

### Compromised MCP client

If an MCP client is compromised, an attacker can issue ability calls on behalf of the authenticated user. Mitigations:

- Enable `production-safe` mode on all sites that are not development or staging.
- Monitor the audit log at `/wp-json/stonewright/v1/audit-log` for unexpected
  ability names or unusual argument patterns. Coverage is **Stonewright-owned
  mutations only**: abilities that call `AbilityKernel::audit()` and
  POST/PUT/PATCH/DELETE routes under `stonewright/v1` (central middleware with
  dedupe). Status vocabulary is `ok` | `error` | `blocked`. Unrelated WordPress
  REST traffic is not logged.
- Use the `ConfirmationToken` mechanism for any custom destructive abilities you add.

### Supply chain

Stonewright depends on `wordpress/mcp-adapter`, `wordpress/abilities-api`, and
`opis/json-schema`. Check these dependencies for security advisories on each
update. The Composer `composer.lock` file pins exact versions; review it when
updating.

`wordpress/abilities-api` is kept as a compatibility package for WordPress
versions that do not yet ship the Abilities API in core. Packagist marks the
package as abandoned with no replacement, so Stonewright configures Composer
audit to report abandoned packages without failing when there are zero security
advisories. Remove the compatibility package only when Stonewright's supported
WordPress floor includes the core Abilities API.

### Companion exposure

The companion Node server must not be exposed to the public internet. Run it on a private network or loopback interface and set `COMPANION_BEARER_TOKEN` and `COMPANION_ALLOWED_ORIGINS` before starting it. The companion can run tokenized WP-CLI commands, including write commands, so treat access to it like access to a privileged local operator. Use `stonewright/php-execute` for PHP runtime snippets; the companion blocks WP-CLI PHP/shell entry points such as `eval`, `eval-file`, and `shell`, and it does not call WordPress REST write endpoints.

## Hardening checklist

- [ ] HTTPS enabled on the WordPress installation.
- [ ] `stonewright_mode` set to `production-safe` on production sites.
- [ ] Dedicated Application Password for the MCP client with the minimum required role.
- [ ] `COMPANION_BEARER_TOKEN` set to a strong random value.
- [ ] `COMPANION_ALLOWED_ORIGINS` restricted to known request origins.
- [ ] Companion running on a private network only.
- [ ] Audit log monitored or exported to a centralized logging system.
- [ ] `WP_DEBUG` off in production (prevents diagnostic information leakage).

## Reporting

See [plugin/SECURITY.md](../plugin/SECURITY.md) for the vulnerability reporting process.
