# Stonewright admin-ui e2e

Playwright gate for Stonewright wp-admin surfaces. Part of the Phase 0 baseline
and the `e2e:admin-ui` CI job.

## What it checks

For each Stonewright admin page in `tests/helpers/admin-pages.ts` (Overview, Setup,
Troubleshoot, AI Abilities, Skills, Memory, Context, Design, Prompt library, Custom
code, Custom code approval, Audit log, Block queue, Rescue, Changes):

- HTTP status &lt; 400
- No horizontal overflow (`scrollWidth - clientWidth <= 0`)
- No product console errors
- No serious or critical axe finding (WCAG 2.0, 2.1 and 2.2 level A and AA) at
  1440 and 390 px, apart from the rule ids in the page's allowance
- Screenshot archived under `artifacts/` (gitignored)

### UI contract (`tests/ui-contract.spec.ts`)

The contract has two parts.

**The component sheet** (`plugin/tests/fixtures/admin-ui/component-sheet.html`) is a page
rendered from the PHP helpers in `plugin/includes/Admin/Ui/`, loaded next to the
legacy shell stylesheets. It needs no WordPress login: the page and the stylesheets
and script of the shared UI layer are served from the repository through request
interception. At every viewport it must have no overflow, no text under 12px, no
target under 24px, no duplicate id, every control and field named, no axe finding of
any impact (also with each dialog and drawer open), stacking tables below 783px,
and 40px or larger controls at 782px and below. Once, at 1440px, it checks the
behaviour: tabs, dialogs (safe action focused, Tab wraps, Escape returns focus, a typed
confirmation is cleared on close), the drawer, copy, secret reveal, remembered
disclosures, the `/` shortcut, toasts and inserted notices, a visible 2px focus ring on
every control, the accent contrast in all nine WordPress colour schemes (the current
palette and the brighter one earlier releases carried), reduced motion (no animation or
transition runs), forced colours (borders, icons and a highlighted selection survive),
no transition over 240ms, and that no rule of the layer reaches markup outside `.sw-ui`.

After changing a helper, regenerate the sheet and run the sheet tests locally:

```bash
(cd ../plugin && STONEWRIGHT_UPDATE_FIXTURES=1 composer test -- --filter ComponentSheetSnapshotTest)
WP_BASE_URL=http://127.0.0.1:8888 npx playwright test ui-contract.spec.ts --grep "UI layer on the component sheet"
```

(`WP_BASE_URL` only has to answer the setup check; the sheet tests do not use it.)

**The pages** are measured with the probe in `tests/helpers/ui-probe.ts` at 1440 and
390px: the first h1 starts within the budget, sticky chrome covers at most 25% of the
viewport, no plugin-owned notice sits in the "other WordPress notices" drawer, every
primary button is painted with the accent fill, no unnamed control, no unlabelled
field, and counts of text under 12px, targets under 24px and repeated ids that stay
within the page's allowance.

**The shell** (the last part of `ui-contract.spec.ts`, run at every viewport) holds the frame
every page is printed in: the band with a link to every page in the groups of `STONEWRIGHT_BAND`
(the page that is open marked), one visible `h1` that names the page and sits within 120px of
the band's bottom edge at 1440 (200px at 390), a tab bar only on a page with tabs of its own
(`STONEWRIGHT_OWN_TABS`), the skip link first and landing in the content region, `hr.wp-header-end` between the header and the content, the
sidebar in hub order, the EXP marker in the band and the sidebar and no Beta badge, notices that stay in place up to three and
fold into one disclosure above that (open for an error or a warning, never holding a
notice the plugin printed, never removed on a timer), headings without core's margins, the
plugin row links and the two Help tabs. A page added to `STONEWRIGHT_PAGES` names its hub
and its band link, so it is covered by all of this at once.

**The band** (`tests/top-band.spec.ts`) holds the band and the EXP markers on the real pages: the
mark and name, the groups with their labels and rules, the current link, the sizes and colours of
the links, the band's position and padding at each width, the EXP marker's position, the tooltip
on hover, focus and Escape (centred 8px above the link, 8px inside the viewport at 320px, above
the admin bar, a fade that reduced motion removes), the sidebar tooltip in the sidebar, the folded
flyout and the mobile menu, and no horizontal overflow at 1440, 1024, 782, 400 and 320px.

`tests/helpers/ui-budget.ts` holds those allowances. They only go down: when a page
adopts the shared UI layer, delete its entry. A page with no entry has no allowance
beyond the navigation chrome the shell prints. A run that measures less than an
allowance adds an `allowance-unused` annotation to the report so the entry is not
forgotten; axe rule ids in an allowance that are no longer reported add an
`axe-allowance-unused` annotation.

**The consent screen** has no slug: it exists for a pending authorization request. `tests/helpers/consent.ts`
registers a throw-away client through the site's own OAuth route, starts an authorization request with PKCE and
follows the redirect, and `admin-ui.spec.ts` and `ui-contract.spec.ts` hold the screen to the page loop's gates (no
overflow, no console error, axe, one h1 near the top, one primary action, no unnamed control, the destination keeps
its port). A site that does not serve OAuth skips it.

**Activity behaviour** (`tests/activity-pages.spec.ts`, desktop only): the Audit log drawer, the typed delete
confirmation (opened, never submitted), the matching rules stated under the filters and reduced motion with the
drawer open; the Troubleshoot run against a synthetic report (results above the fold, summary in words, a failed
request that leaves a notice standing).

Projects cover the supported light theme at five viewports:

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
WP_BASE_URL=http://site-under-test.local \
WP_USERNAME=admin \
WP_PASSWORD=your-password \
npm test
```

## CI

The `e2e-admin-ui (<group>)` jobs in `.github/workflows/ci.yml` run in parallel, one per viewport group
(`desktop-1440`; `desktop-1024-tablet-782`; `mobile-390-320`). Each one:

1. Installs clean production plugin dependencies
2. Builds and extracts the exact release ZIP layout
3. Verifies every Jetpack Autoloader manifest path in that extracted archive
4. Starts its own `wp-env` from `e2e/.wp-env.package.json` with pinned Elementor 3.30.0 and WooCommerce active
5. Runs `npx playwright test` with the `--project` options of its group

The `e2e-admin-ui` job passes only when every group passes. To run one group locally, pass the same
options, for example `npx playwright test --project=mobile-390-light --project=mobile-320-light`.

## WordPress matrix (Phase 12)

| Config | Core | PHP | Use |
|---|---|---|---|
| `.wp-env.json` | **6.9** | 8.2 | Default CI / local |
| `.wp-env.package.json` | **6.9** | 8.2 | CI release-archive + WooCommerce gate |
| `.wp-env.6.7.json` | **6.7** | 8.1 | Optional compatibility pin |

```bash
# Default (6.9)
npx wp-env start

# Optional 6.7 matrix
npx wp-env start --config .wp-env.6.7.json
```

Plugin requires WordPress **6.7+**. When WordPress 7.0 ships, add `.wp-env.7.0.json`
alongside the same plugin mount.

## Notes

- Screenshots under `e2e/artifacts/` are not committed. Baseline reference path:
  `docs/plans/evidence/phase-0/` (see that README for viewport matrix + invariants).
- Packaging smoke (plugin ZIP layout, vendor include / tests exclude):
  `node scripts/package-verify.mjs`
