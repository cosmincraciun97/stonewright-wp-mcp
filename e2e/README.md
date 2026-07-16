# Stonewright admin-ui e2e

Playwright gate for Stonewright wp-admin surfaces. Part of the Phase 0 baseline
and the `e2e:admin-ui` CI job.

## What it checks

For each Stonewright admin page (Dashboard, Setup, AI Abilities, Blueprints,
Sandbox, Skills, Memory, Audit Log):

- HTTP status &lt; 400
- No horizontal overflow (`scrollWidth - clientWidth <= 0`)
- No product console errors
- Screenshot archived under `artifacts/` (gitignored)

Projects cover five viewports × light/dark color schemes:

| Viewport | Size |
|---|---|
| desktop-1440 | 1440×900 |
| desktop-1024 | 1024×768 |
| tablet-782 | 782×1024 |
| mobile-390 | 390×844 |
| mobile-320 | 320×568 |

## Prerequisites

- Node 20+
- Docker (for `@wordpress/env`)
- Plugin vendor installed: `cd ../plugin && composer install`

## Local run against wp-env

```bash
cd e2e
npm install
npx playwright install chromium
# Install plugin production deps so the mounted plugin boots cleanly
(cd ../plugin && composer install --no-interaction)
npx wp-env start
# default URL: http://localhost:8888  user: admin / password
npm test
npx wp-env stop
```

## Local run against a Local / existing site

```bash
cd e2e
npm install
npx playwright install chromium
WP_BASE_URL=http://transavia-local.local \
WP_USERNAME=admin \
WP_PASSWORD=your-password \
npm test
```

## CI

The `e2e-admin-ui` job in `.github/workflows/ci.yml`:

1. Installs plugin Composer deps
2. Starts `wp-env` from `e2e/.wp-env.json`
3. Runs `npx playwright test`

## Notes

- WordPress core pin in `.wp-env.json` is **6.9** (stable for CI). Phase 12
  extends the matrix to 6.7 / 6.9 / 7.0.
- Screenshots under `e2e/artifacts/` are not committed. Baseline reference
  screenshots for regression live under `docs/plans/evidence/phase-0/`.
