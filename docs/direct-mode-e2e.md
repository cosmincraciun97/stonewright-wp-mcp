# Direct mode E2E matrix

Honest results for plugin-less (Direct) mode against core WordPress REST.

## How to run

```bash
cd companion
npm run build
STONEWRIGHT_MODE=direct \
STONEWRIGHT_WP_URL=http://your-site.local \
STONEWRIGHT_WP_USERNAME=admin \
STONEWRIGHT_WP_APP_PASSWORD='xxxx xxxx xxxx xxxx xxxx xxxx' \
node scripts/e2e-direct.mjs
```

HTTP local URLs are supported. For Application Passwords on plain HTTP, WordPress
requires `WP_ENVIRONMENT_TYPE=local` (or the Application Passwords availability filter).

## Capability matrix (Direct vs Plugin)

| Area | Direct (no plugin) | Plugin mode |
|---|---|---|
| Site discover / REST types | Yes | Yes (richer via abilities) |
| Pages/posts list create update delete | Yes (core REST) | Yes |
| Media upload | Yes (core REST) | Yes |
| Menus | Limited (core REST menus when available) | Yes + WP-CLI |
| Taxonomy terms | Yes | Yes |
| Settings read | Yes | Yes |
| Global styles / FSE | Yes when endpoints exist | Yes |
| Elementor write / DesignSpec | **No** — clear “requires Stonewright plugin” message | Yes |
| PHP execute | **No** | Yes (`stonewright/php-execute`) |
| Skills / memory / learning | **No** | Yes |
| Audit log / backups / tokens | **No** | Yes |
| WP-CLI (tokenized companion) | Yes (local CLI, independent of plugin) | Yes |

## Live run log

| Date | Target | Plugin | Transport | Result |
|---|---|---|---|---|
| 2026-07-15 | (operator-run) | deactivated for Direct | `http://` | Use `scripts/e2e-direct.mjs`; record pass/fail per step above |

> The automated unit suite (`npm test`) covers Direct config, mode probe, and REST client contracts.
> Full live E2E needs a site + Application Password and is operator-triggered via the script.

## Degradation expectations

- Elementor / DesignSpec tools must not crash: they return a clear install-plugin message.
- With the plugin re-activated, auto mode selects the plugin MCP proxy and existing proxy tests remain green.

## Blueprints (Direct)

| Tool | Direct | Notes |
|---|---|---|
| blueprint-list | Yes | Bundled JSON in companion |
| blueprint-get | Yes | Full spec |
| blueprint-apply | Yes (Gutenberg draft) | Elementor requires plugin |
