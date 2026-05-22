# Local development notes

## Environment quirks

### `transavia-local` WordPress site

The local WordPress site named `transavia-local` (registered in `~/.config/wp-sites.json`) fails
when accessed through the Claudeus MCP proxy. The proxy reports a connection or authentication
error depending on the session.

However, the site's REST API responds normally when hit directly:

```
http://localhost:8882/wp-json/
```

**Working hypothesis:** Claudeus resolves the site slug differently from the raw URL, or the
bearer token forwarding is broken for this site's configuration. Direct REST calls (via curl or
the browser) work fine; MCP-tunneled calls do not.

**Until fixed:** use the direct URL in any manual REST debugging, and register the site in
`.env` for the companion's proxy target if needed.
