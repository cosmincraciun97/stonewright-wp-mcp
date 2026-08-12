# Product Clarity and Release Metadata Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Stonewright MCP understandable in the first screen, shorten the default setup path, document evidence-backed use cases, expose correct license metadata, and make future beta releases true prereleases without breaking native updates.

**Architecture:** Public docs lead with the proof chain and a four-step Plugin path, then progressively disclose the 360-ability surface and advanced modes. Root license metadata becomes canonical AGPL text with a separate component map. The updater selects releases by the installed SemVer channel from an explicit release list and fails closed across channels.

**Tech Stack:** Markdown, GitHub Releases API, PHP 8.1, PHPUnit, GitHub Actions, Node repository checks.

## Global Constraints

- No website work and no npm publication.
- Do not rewrite or republish beta.9 history.
- Do not use absolute safety claims. Describe concrete controls and recovery evidence.
- Do not mention private projects, sites, aliases, IDs, screenshots, memory, audit payloads, or credentials.
- Use Stonewright MCP as the product name in explanatory copy.
- Keep the current dashboard image unless a change is required by factual drift; do not fabricate live data.
- Exact versions remain out of evergreen install docs except allowed metadata, changelogs, and historical release notes.
- Future beta tags become prereleases only after channel-aware updater tests pass.
- Add tests before updater/workflow changes.

---

## Task 1: Rebuild the README first screen around the proof chain

**Files:**

- Modify: `README.md`
- Reference: `assets/screenshots/stonewright-dashboard-beta9.svg`
- Reference: `docs/ability-truth-matrix.md`
- Reference: `docs/installation.md`

- [ ] Replace the opening descriptor with:

  `Your AI does not just change WordPress. Stonewright proves what changed and gives you a way back.`

- [ ] Add the category line:

  `Guarded, recoverable WordPress and Elementor automation for AI agents.`

- [ ] Keep one supporting line that states the compact task-aware MCP surface is backed by 360 Plugin abilities and 100 Direct tools. Counts must remain generated-source facts.
- [ ] Move the safety disclaimer next to claims it qualifies, using concrete controls instead of an unqualified `safe` label.
- [ ] Add a short `How it works` chain before the capability ledger: inspect -> plan/dry-run -> approve when required -> back up -> write -> read back/verify -> audit/restore.
- [ ] Keep the existing screenshot immediately after primary links. Verify logo, header, active tab, and labels match the shipped beta.9 admin UI.
- [ ] Move full category counts and mode details below onboarding/use cases. Do not delete them.
- [ ] Run Markdown/public hygiene checks and inspect the rendered GitHub preview locally or through a Markdown renderer.

```bash
node scripts/check-docs-freshness.mjs
node scripts/check-public-hygiene.mjs --require-private-terms --history
git diff --check
```

**Commit:** `docs: clarify Stonewright proof chain`

---

## Task 2: Reduce default Plugin setup to four steps

**Files:**

- Modify: `README.md`
- Modify: `docs/installation.md`
- Modify: `docs/install-prompts.md`
- Modify: `plugin/README.md`
- Modify: `companion/README.md`
- Reference: `plugin/includes/Admin/ConfigurationPage.php`
- Reference: `companion/src/connect-cli.ts`

- [ ] Make the default flow exactly: install Plugin ZIP; open Stonewright -> Setup and connect; restart and verify; call `stonewright-task-start` first.
- [ ] Put OAuth/Application Password selection, installer flags, profile variants, Direct mode, remote HTTP, browser consent, and recovery commands behind explicit advanced sections.
- [ ] Keep `essential` as the recommended working surface and `bootstrap` as startup diagnostics only.
- [ ] Confirm every button/label and command against current Setup UI and CLI source. A parseable config is not runtime proof.
- [ ] Preserve credential rules: hidden prompt or environment reference; never argv/chat/repository.
- [ ] Ensure Plugin-only instructions fail closed instead of silently falling back to Direct mode.
- [ ] Search for contradictory six-step/default-bootstrap language and update maintained docs only.

```bash
rg -n "six steps|bootstrap.*recommended|Quick Start|plugin-only|task-start" README.md plugin/README.md companion/README.md docs
node scripts/check-docs-freshness.mjs
git diff --check
```

**Commit:** `docs: simplify first connection`

---

## Task 3: Add three evidence-backed use cases and a comparison guide

**Files:**

- Create: `docs/why-stonewright.md`
- Modify: `README.md`
- Modify: `docs/architecture.md`
- Modify: `scripts/docs-manifest.json`

- [ ] Add three concise use cases linked to existing contracts:
  - Elementor repair through live schema, surgical batch, backup, write verification, and browser recipe.
  - Custom-code change through provider discovery, dry-run, human approval, apply, readback, and rollback.
  - Repeated-failure handling through classification, incident action, verified repair, and controlled learning.
- [ ] Add a generic comparison table: generic WordPress API bridge versus Stonewright MCP. Compare typed writes, schema evidence, backups, approvals, verification, incident lifecycle, restore, Direct limitations, and task-aware tool surfaces.
- [ ] Do not name or disparage competitors. Do not compare raw tool counts as the primary value.
- [ ] Link every strong claim to a repository contract, matrix, test-backed document, or source-readable behavior.
- [ ] Register the new maintained document in `scripts/docs-manifest.json`.
- [ ] Run docs freshness/link/public-hygiene gates.

**Commit:** `docs: add guarded workflow use cases`

---

## Task 4: Replace root license text with canonical AGPL metadata

**Files:**

- Replace: `LICENSE`
- Create: `LICENSING.md`
- Modify: `README.md`
- Modify: `plugin/README.md`
- Modify: `docs/upstream-code-reuse.md`
- Create: `scripts/check-license-metadata.mjs`
- Modify: `.github/workflows/ci.yml`
- Modify: `.github/workflows/release.yml`

- [ ] Add a failing metadata test that requires root `LICENSE` to match the canonical unmodified GNU AGPL v3 text and requires `companion/LICENSE` to remain MIT.
- [ ] Obtain the canonical text from the official GNU license source or an installed vetted SPDX/license package; verify its SHA-256 in the script. Do not paraphrase it.
- [ ] Replace the short root notice with that canonical text.
- [ ] Add `LICENSING.md`: Plugin and Visual are AGPL-3.0-or-later; companion is MIT; third-party files retain recorded SPDX/copyright terms.
- [ ] Point README badges/license links to `LICENSE`, `companion/LICENSE`, and `LICENSING.md`.
- [ ] Add the metadata check to CI and release gates before packaging.
- [ ] Run the new test, public hygiene, and package checks.

```bash
node scripts/check-license-metadata.mjs
node scripts/check-public-hygiene.mjs --require-private-terms --history
node scripts/package-verify.mjs
```

**Commit:** `docs: expose component license metadata`

---

## Task 5: Specify channel selection with failing updater tests

**Files:**

- Modify: `plugin/tests/Unit/Core/GitHubUpdaterTest.php`
- Add fixtures under: `plugin/tests/fixtures/github/`
- Modify: `plugin/tests/bootstrap.php` only if HTTP list-response support is missing.

- [ ] Add fixtures containing stable, beta, draft, malformed, incomplete-asset, and cross-channel releases.
- [ ] Add tests for `GitHubUpdater::installed_channel(string $version): string` returning `stable` or `beta`.
- [ ] Add tests for `GitHubUpdater::select_release(array $releases, string $channel): ?array`:
  - stable ignores drafts and prereleases;
  - beta selects the highest non-draft compatible prerelease;
  - malformed tags and mismatched exact asset names are rejected;
  - missing Plugin or companion asset rejects the candidate;
  - no eligible candidate returns null without crossing channels.
- [ ] Add cache tests proving selected channel is stored and a stable cache cannot satisfy beta or vice versa.
- [ ] Add `inject_update` tests for installed stable and beta versions.
- [ ] Run the focused suite and confirm failures describe missing channel behavior.

```bash
cd plugin
composer test -- --filter GitHubUpdaterTest
```

**Commit:** `test: define release channel selection`

---

## Task 6: Implement fail-closed channel-aware update discovery

**Files:**

- Modify: `plugin/includes/Core/GitHubUpdater.php`
- Modify: `plugin/tests/Unit/Core/GitHubUpdaterTest.php`
- Modify: `plugin/tests/Unit/Admin/CompanionUpdateStatusTest.php`

- [ ] Replace `/releases/latest` with the GitHub releases-list endpoint using bounded pagination sufficient for supported channels.
- [ ] Derive the channel from the installed `STONEWRIGHT_VERSION`; no new user setting is needed.
- [ ] Parse only non-draft releases. Stable accepts no prerelease suffix and `prerelease=false`; beta requires a SemVer prerelease and `prerelease=true`.
- [ ] Require exact tag/version and exact Plugin plus companion asset filenames from the trusted release-download origin.
- [ ] Cache `{channel, release}` under channel-specific keys or validate channel on read. Preserve force refresh.
- [ ] If the request, JSON, selection, or assets fail, return null and keep the installed version. Never fall back across channels.
- [ ] Keep Plugin details and companion-status consumers on the same selected release.
- [ ] Re-run updater/admin focused tests, then Plugin suite.

```bash
cd plugin
composer test -- --filter 'GitHubUpdaterTest|CompanionUpdateStatusTest'
composer test
composer phpstan
composer phpcs
```

**Commit:** `feat: select updates by release channel`

---

## Task 7: Mark only future prerelease tags correctly

**Files:**

- Modify: `.github/workflows/release.yml`
- Create: `scripts/release-flags.mjs`
- Create: `scripts/tests/release-flags.test.mjs`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/releases/checklist.md`
- Modify: `docs/release-playbook.md` if present

- [ ] Add failing tests: `1.0.0-beta.10` and `1.0.0-rc.1` produce `--prerelease`; `1.0.0` produces `--latest`; malformed tags fail.
- [ ] Implement a small deterministic parser. It must not call GitHub or mutate releases.
- [ ] Update the release workflow to derive flags from the exact tag after all updater tests/package gates pass.
- [ ] Remove unconditional `--latest`. Never pass both `--latest` and `--prerelease`.
- [ ] Document that beta.9 remains historical and untouched; behavior applies to future tags.
- [ ] Add the parser test to CI.
- [ ] Validate workflow syntax and run the script tests.

```bash
node --test scripts/tests/release-flags.test.mjs
git diff --check .github/workflows/release.yml
```

**Commit:** `ci: classify future release tags`

---

## Task 8: Refresh maintained documentation and change records

**Files:**

- Modify: `CHANGELOG.md`
- Modify: `plugin/CHANGELOG.md`
- Modify: `docs/roadmap.md`
- Modify: `docs/architecture.md`
- Modify: `docs/documentation-maintenance.md`
- Modify: `docs/releases/checklist.md`
- Modify other maintained Markdown only when behavior changed.

- [ ] Record product behavior, updater channel safety, license metadata, and documentation changes under Unreleased. Do not imply a published version.
- [ ] Update architecture to show incident proof/learning closure and release-channel selection at the correct level.
- [ ] Keep generated/imported Markdown untouched except through its generator/importer.
- [ ] Search all maintained Markdown for stale default setup, old license links, unconditional latest-release assumptions, and unsupported learning claims.
- [ ] Run freshness and diff checks.

```bash
node scripts/check-docs-freshness.mjs
git diff --check
```

**Commit:** `docs: align architecture and release guidance`

---

## Task 9: Full repository and package verification

- [ ] Run Plugin gates.

```bash
cd plugin
composer install
composer docs:matrix
composer test
composer phpstan
composer phpcs
composer security:audit
composer dependencies:audit
composer package:verify-manifests
```

- [ ] Run companion gates even if no npm publication occurs.

```bash
cd companion
npm ci
npm run typecheck
npm test
npm run build
```

- [ ] Run Visual gates and stage its production bundle if required by package verification.

```bash
cd visual
npm ci
npm run typecheck
npm test
npm run build
```

- [ ] Run repository-wide checks.

```bash
cd ..
node scripts/check-license-metadata.mjs
node scripts/check-docs-freshness.mjs
node scripts/check-public-hygiene.mjs --require-private-terms --history
node scripts/package-verify.mjs --require-visual-bundle --strict-vendor
git diff --check
```

- [ ] Inspect the Plugin ZIP simulation and companion/Visual package contents for source exclusions, credentials, private terms, runtime state, and license files.
- [ ] Review README rendering on desktop and narrow viewport.
- [ ] Review the entire diff. Remove unrelated changes. Confirm beta.9 release/tag/assets were not mutated.
- [ ] Do not publish a site, npm package, GitHub release, or tag.

**Commit:** `test: verify public release metadata`

---

## Task 10: Merge readiness

- [ ] Verify Git author email and active GitHub account before any final commit, push, PR, merge, tag, or release action.
- [ ] Rebase/merge current `origin/main` only through the repository's normal non-destructive workflow; never force-push for contributor UI.
- [ ] Prepare one PR covering both approved slices. PR description must list changed abilities, security gates, public docs, update-channel behavior, package checks, and explicit exclusions: no website, no npm publication, no release.
- [ ] Request review only after local gates pass.
- [ ] Wait for all required CI checks. Fix failures on the topic branch and rerun relevant local gates.
- [ ] Merge only after CI is green and review requirements are satisfied.
- [ ] Leave version bump, tag, and release to a separately approved release task.
