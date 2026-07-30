# Phase 0 baseline evidence

Regression reference for the `e2e:admin-ui` Playwright gate scaffolded in Phase 0.

## Benchmarks / evidence path

| Artifact | Path |
|---|---|
| This baseline README | `docs/plans/evidence/phase-0/` |
| MCP token budgets (historical PR series) | `docs/benchmarks/` |
| Playwright artifacts (local, gitignored) | `e2e/artifacts/` |
| Packaging smoke | `node scripts/package-verify.mjs` |

Promote screenshots into this directory only when deliberately freezing a visual baseline for a PR.

## Gate

- Package: `e2e/` (`@playwright/test`)
- Spec: `e2e/tests/admin-ui.spec.ts`
- Fixture: `e2e/.wp-env.json` (WordPress **6.9** default; optional `e2e/.wp-env.6.7.json` for WP **6.7**)
- Viewports: 1440×900, 1024×768, 782×1024, 390×844, 320×568 × light/dark

## Pages covered

| Label | `page` slug |
|---|---|
| Dashboard | `stonewright-status` |
| Setup | `stonewright` |
| AI Abilities | `stonewright-abilities` |
| Blueprints | `stonewright-blueprints` |
| Sandbox | `stonewright-sandbox` |
| Skills | `stonewright-skills` |
| Memory | `stonewright-memory` |
| Audit Log | `stonewright-audit-log` |

## Invariants (per page)

1. HTTP status &lt; 400
2. No horizontal overflow (`documentElement.scrollWidth - clientWidth <= 0`)
3. No product console errors
4. Screenshot captured under `e2e/artifacts/` (gitignored)

## Findings (scaffold baseline)

- Scaffold committed 2026-07-16 with the Phase 0 PR.
- Full interactive capture against a live Local site or wp-env should be attached to the Phase 0 PR when CI/local evidence is available; regenerate with:

  ```bash
  cd e2e && npm test
  # copy representative screenshots here if promoting a new baseline
  ```

- Known follow-ups deferred to Phase 9: keyboard-complete flows, theme-toggle
  `aria-pressed` truth, axe-style accessible-name checks, focus trap on dialogs.
