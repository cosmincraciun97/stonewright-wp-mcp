# Release Channel Policy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Stonewright release channel selection explicit, fail-closed, easy for users to discover, and governed by a mandatory maintainer decision before publication.

**Architecture:** A pure Node helper will read a channel declaration from the versioned release note and validate it against SemVer before returning the single GitHub CLI flag. `AGENTS.md` owns the maintainer/agent decision rules, documentation freshness owns the current-release README block, and the release workflow consumes the validated helper rather than inferring channel from the version suffix.

**Tech Stack:** Node.js ESM and `node:test`, GitHub Actions YAML and GitHub CLI, Markdown, existing documentation-freshness/public-hygiene gates.

## Global Constraints

- Supported public beta means prerelease SemVer plus GitHub normal release and `Latest`.
- Preview means prerelease SemVer plus GitHub `Pre-release`, never `Latest`.
- Stable means stable SemVer plus GitHub normal release and `Latest`.
- Missing, unknown, or incompatible version/channel combinations fail closed.
- A tag, release publication, channel conversion, or stable declaration requires explicit maintainer approval after a release decision record.
- Exact supported-release URLs may appear only in the README block validated against the canonical plugin version.
- Existing beta.10 asset bytes and digests must not change during its metadata-only channel conversion.
- No repository text, branch, commit, pull request, changelog, or release note may describe private application strategy or promotional optimization.
- Git operations require author email `61551533+cosmincraciun97@users.noreply.github.com` and GitHub CLI account `cosmincraciun97`.

---

### Task 1: Explicit, fail-closed release channel helper

**Files:**
- Modify: `scripts/release-flags.mjs`
- Modify: `scripts/tests/release-flags.test.mjs`
- Modify: `.github/workflows/release.yml`
- Modify: `docs/releases/1.0.0-beta.10.md`

**Interfaces:**
- Consumes: `releaseFlags(version: string, channel: string): string[]` and release-note Markdown containing `Release channel: \`supported\``.
- Produces: CLI `node scripts/release-flags.mjs <version> <notes-path>` writing exactly `--latest` or `--prerelease`.

- [ ] **Step 1: Write failing channel tests**

Replace inference tests with exact assertions:

```js
assert.deepEqual(releaseFlags('1.0.0-beta.10', 'supported'), ['--latest']);
assert.deepEqual(releaseFlags('1.0.0-beta.11', 'preview'), ['--prerelease']);
assert.deepEqual(releaseFlags('1.0.0-rc.1', 'preview'), ['--prerelease']);
assert.deepEqual(releaseFlags('1.0.0', 'stable'), ['--latest']);
assert.throws(() => releaseFlags('1.0.0-beta.10', ''), /release channel/i);
assert.throws(() => releaseFlags('1.0.0-beta.10', 'stable'), /incompatible/i);
assert.throws(() => releaseFlags('1.0.0', 'preview'), /incompatible/i);
assert.throws(() => releaseFlags('1.0.0', 'supported'), /incompatible/i);
```

Add release-note parser assertions:

```js
assert.equal(releaseChannelFromNotes('Release channel: `supported`\n'), 'supported');
assert.throws(() => releaseChannelFromNotes('# Missing'), /release channel/i);
assert.throws(() => releaseChannelFromNotes('Release channel: `other`'), /release channel/i);
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `node --test scripts/tests/release-flags.test.mjs`

Expected: failure because `releaseFlags` still accepts one argument and `releaseChannelFromNotes` is absent.

- [ ] **Step 3: Implement strict parsing and compatibility validation**

Implement:

```js
const channels = new Set(['supported', 'preview', 'stable']);

export function releaseChannelFromNotes(notes) {
	const match = /^Release channel: `([^`]+)`$/m.exec(notes);
	if (!match || !channels.has(match[1])) {
		throw new Error('Release notes require one valid release channel.');
	}
	return match[1];
}

export function releaseFlags(version, channel) {
	const match = semver.exec(version);
	if (!match) throw new Error(/* existing semantic-version message */);
	if (!channels.has(channel)) throw new Error('Expected release channel: supported, preview, or stable.');
	const prerelease = Boolean(match[1]);
	if (channel === 'stable' && prerelease) throw new Error('Release channel is incompatible with the semantic version.');
	if (channel !== 'stable' && !prerelease) throw new Error('Release channel is incompatible with the semantic version.');
	return [channel === 'preview' ? '--prerelease' : '--latest'];
}
```

The CLI reads the UTF-8 notes path from `process.argv[3]`, parses its channel,
and prints the validated flag. Missing files or declarations exit nonzero.

- [ ] **Step 4: Wire workflow and current release note**

Add directly after the release date:

```markdown
Release channel: `supported`
```

Change the workflow invocation to:

```bash
release_flag="$(node scripts/release-flags.mjs "$version" "$notes")"
```

Do not fall back to `docs/releases/checklist.md`; a missing versioned release
note must block publication.

- [ ] **Step 5: Run focused tests and workflow parse**

Run:

```bash
node --test scripts/tests/release-flags.test.mjs
node scripts/release-flags.mjs 1.0.0-beta.10 docs/releases/1.0.0-beta.10.md
ruby -e "require 'yaml'; YAML.load_file('.github/workflows/release.yml'); puts 'workflow-yaml-ok'"
```

Expected: tests pass, CLI prints `--latest`, YAML prints `workflow-yaml-ok`.

- [ ] **Step 6: Commit**

```bash
git add scripts/release-flags.mjs scripts/tests/release-flags.test.mjs .github/workflows/release.yml docs/releases/1.0.0-beta.10.md
git commit -m "fix: validate release channel selection"
```

### Task 2: Durable agent decision methodology

**Files:**
- Modify: `AGENTS.md`
- Modify: `docs/documentation-maintenance.md`
- Modify: `docs/architecture.md`

**Interfaces:**
- Consumes: release classes and stable gates from the approved design.
- Produces: mandatory `Release decision gate` followed by agents before any version/tag/release operation.

- [ ] **Step 1: Add the release decision gate to `AGENTS.md`**

Add a section after `## Branching and changes` containing:

```markdown
## Release decision gate

- Before changing a version, tag, release state, or release workflow, present a release decision record with: user-visible change, artifact impact, recommended class, SemVer, evidence and gaps, risk and rollback, and required documentation.
- Recommend exactly one class: no release, supported public beta, preview prerelease, or stable release.
- A supported public beta is recommended for general installation: prerelease SemVer, GitHub normal release, and `Latest`.
- A preview prerelease is opt-in and not recommended generally: prerelease SemVer, GitHub `Pre-release`, never `Latest`.
- A stable release uses stable SemVer and `Latest` only after every stable gate below is satisfied.
- Never publish a tag, release, channel conversion, or stable declaration without explicit maintainer approval after the decision record.
```

Include all nine stable gates from the approved specification as concrete bullets.

- [ ] **Step 2: Align maintained documentation**

Update architecture to state that update selection follows installed SemVer
compatibility while GitHub presentation follows the explicit support channel.
Update documentation maintenance to require a channel declaration in every
versioned release note and the release-decision review before tagging.

- [ ] **Step 3: Verify documentation**

Run:

```bash
node scripts/check-docs-freshness.mjs
git diff --check
```

Expected: both exit zero.

- [ ] **Step 4: Commit**

```bash
git add AGENTS.md docs/documentation-maintenance.md docs/architecture.md
git commit -m "docs: codify release decision gates"
```

### Task 3: One obvious supported-download path

**Files:**
- Modify: `README.md`
- Modify: `scripts/check-docs-freshness.mjs`
- Modify: `CHANGELOG.md`
- Test: `scripts/tests/release-flags.test.mjs`

**Interfaces:**
- Consumes: canonical version parsed from `plugin/stonewright.php`.
- Produces: README block delimited by `<!-- supported-release:start -->` and `<!-- supported-release:end -->`, validated against that version.

- [ ] **Step 1: Write failing README contract tests**

Add assertions that README contains both markers, `Public Beta`, the canonical
beta.10 tag URL, plugin ZIP URL, companion TGZ URL, checksum URL, and
`docs/installation.md`. Assert the freshness script names the supported block.

- [ ] **Step 2: Run the focused test and verify RED**

Run: `node --test scripts/tests/release-flags.test.mjs`

Expected: failure because the supported-release block does not exist.

- [ ] **Step 3: Add the compact README release card**

Place it below the badge row and before the product description:

```html
<!-- supported-release:start -->
<p align="center"><strong>Current release: 1.0.0-beta.10 — Public Beta</strong></p>
<p align="center">
  <a href=".../releases/download/v1.0.0-beta.10/stonewright-1.0.0-beta.10.zip">Download Plugin</a>
  · <a href="docs/installation.md">Installation guide</a>
  · <a href=".../releases/download/v1.0.0-beta.10/stonewright-companion-1.0.0-beta.10.tgz">Companion</a>
  · <a href=".../releases/download/v1.0.0-beta.10/SHA256SUMS.txt">Checksums</a>
</p>
<p align="center"><sub>Preview builds appear on the complete Releases page and are not recommended by default.</sub></p>
<!-- supported-release:end -->
```

- [ ] **Step 4: Make freshness own the exception**

Extract exactly one supported-release block. Fail if markers are missing,
duplicated, reversed, or if the block lacks the canonical version and all four
expected URLs. Remove only this validated block before applying the existing
evergreen exact-prerelease-pin rejection to README.

Add an `Unreleased / Changed` root changelog item describing explicit channels
and the supported-download path. Do not change plugin or companion versions.

- [ ] **Step 5: Run focused and documentation tests**

Run:

```bash
node --test scripts/tests/release-flags.test.mjs
node scripts/check-docs-freshness.mjs
node scripts/check-public-hygiene.mjs
git diff --check
```

Expected: all pass.

- [ ] **Step 6: Commit**

```bash
git add README.md scripts/check-docs-freshness.mjs scripts/tests/release-flags.test.mjs CHANGELOG.md
git commit -m "docs: expose the supported release path"
```

### Task 4: Full verification, PR, merge, and channel conversion

**Files:**
- Verify all changed files; no new source file expected.
- External metadata: GitHub release `v1.0.0-beta.10` only after merge.

**Interfaces:**
- Consumes: green branch, explicit user approval already recorded, immutable beta.10 assets.
- Produces: merged policy and beta.10 as normal `Latest` without changed artifacts.

- [ ] **Step 1: Record original asset digests**

Run:

```bash
gh release view v1.0.0-beta.10 --json assets --jq '.assets[] | [.name,.digest] | @tsv'
```

Store the output only in the task evidence, never in a tracked scratch file.

- [ ] **Step 2: Run complete local gates**

Run:

```bash
node --test scripts/tests/release-flags.test.mjs
node scripts/check-license-metadata.mjs
node scripts/check-docs-freshness.mjs
node scripts/check-public-hygiene.mjs
ruby -e "require 'yaml'; YAML.load_file('.github/workflows/ci.yml'); YAML.load_file('.github/workflows/release.yml')"
git diff --check
```

Run the production packaging verification because release workflow behavior changed:

```bash
cd plugin
composer package:verify-manifests
cd ..
node scripts/package-verify.mjs --require-visual-bundle --strict-vendor
```

- [ ] **Step 3: Identity gate, push, and PR**

Verify the exact Git author email and GitHub account, push
`release-channel-policy`, and open a PR titled:

```text
fix: make release channels explicit
```

The PR states that no ability, permission, backup, validation, token, approval,
or audit behavior changed. It lists all public documentation changes.

- [ ] **Step 4: Wait for all PR checks and merge normally**

Do not merge while any check is pending or failed. Merge with a merge commit
after all checks are green and re-run the identity gate immediately before the
merge.

- [ ] **Step 5: Wait for post-merge CI**

Identify the CI run for the exact merge SHA and wait for `success`. Stop if the
head SHA differs or any job fails.

- [ ] **Step 6: Convert beta.10 metadata**

Reconfirm that the release exists, is not a draft, points to the expected tag,
and its asset digests match Step 1. Then run under the approved GitHub account:

```bash
gh release edit v1.0.0-beta.10 --prerelease=false --latest
```

Do not upload, replace, rename, or delete assets and do not move the tag.

- [ ] **Step 7: Verify public result**

Confirm:

- beta.10 reports `isPrerelease=false`, `isLatest=true`, `isDraft=false`;
- beta.9 reports `isLatest=false`;
- tag target remains the beta.10 merge commit already released;
- all four asset names and digests equal Step 1;
- README links return the expected public assets;
- `/releases/latest` resolves to beta.10.

### Task 5: Prepare the maintainer application text outside the repository

**Files:**
- Do not create or modify repository files.
- Deliver the reusable text only in the final task response.

**Interfaces:**
- Consumes: public repository facts verified during implementation.
- Produces: three truthful form answers, each at most 500 characters.

- [ ] **Step 1: Draft the maintainer-role answer**

State that the applicant is the primary maintainer responsible for architecture,
review, releases, security gates, documentation, and compatibility. Do not
claim a team or adoption not supported by public evidence.

- [ ] **Step 2: Draft the qualification answer**

Explain ecosystem importance through the problem solved: guarded WordPress MCP
automation, recoverable writes, evidence, Plugin/Direct modes, and maintained
release infrastructure. Mention public tests/capability scope only when exact.

- [ ] **Step 3: Draft the API-credit answer**

Describe use for issue triage, regression-test generation, security review,
compatibility validation, release verification, and documentation maintenance.
Keep it concrete and under 500 characters.

- [ ] **Step 4: Final truthfulness check**

Verify every metric against the public repository. Omit stars, downloads,
users, installations, or community adoption unless directly verified at the
time of delivery.
