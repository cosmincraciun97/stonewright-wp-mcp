# Release Channel Policy Design

Date: 2026-08-12
Status: Approved for implementation

## Objective

Make every Stonewright release decision predictable for maintainers, agents,
updaters, and users. The version string and the GitHub release channel serve
different purposes:

- SemVer communicates API maturity.
- GitHub's `Latest` marker identifies the version recommended for installation.
- GitHub's `Pre-release` marker identifies an opt-in preview that is not the
  recommended installation.

Stonewright may therefore recommend a supported public beta as `Latest` while
retaining its `1.0.0-beta.N` SemVer version.

## Release classes

### No release

Use no release when a change affects only repository documentation, tests,
plans, comments, or internal development automation and does not change any
artifact consumed by users. Merge the change normally. Do not create an empty
version bump merely to advertise repository activity.

### Supported public beta

Use a supported public beta when the product is still before the stable 1.0
contract but the build is the version recommended to all new and existing
users.

- Version: `1.0.0-beta.N`.
- GitHub state: normal release and `Latest`.
- Updater state: selected by prerelease installations as their supported
  channel.
- Documentation: clearly label it `Public Beta` and state that APIs or
  compatibility may still change before 1.0.
- Distribution: publish the plugin ZIP, companion TGZ, Visual TGZ, release
  notes, and checksums.

### Preview prerelease

Use a GitHub prerelease only when the build is intentionally not recommended
for general installation. Typical reasons are incomplete compatibility,
experimental contracts, a risky migration, a limited validation matrix, or an
explicit request for early testing.

- Version: an appropriate beta or release-candidate SemVer identifier.
- GitHub state: `Pre-release`, never `Latest`.
- Documentation: name the missing evidence and the intended tester audience.
- Distribution: may contain the normal artifacts, but installation guidance
  must not silently select it for general users.

The existence of a prerelease suffix alone does not force this channel. The
maintainer's support recommendation does.

### Stable release

Use a stable release only when the public contract is ready to carry normal
SemVer compatibility expectations.

- Version: `1.0.0` or a later stable SemVer version.
- GitHub state: normal release and `Latest`.
- Updater state: stable installations select only stable releases.
- Documentation: remove public-beta warnings only when the stable gates below
  are satisfied.

## Stable 1.0 gates

An agent must recommend against 1.0 while any required gate is missing:

1. No unresolved P0 or P1 defect affecting installation, authentication,
   permissions, backups, writes, update discovery, or recovery.
2. Plugin and Direct contracts are documented, versioned, and internally
   consistent; intentional experimental abilities are explicitly marked.
3. The supported client matrix has runtime evidence for installation,
   `stonewright-task-start`, status, required tools, and an empty required-tool
   refresh list after restart.
4. Plugin, companion, and updater version alignment is verified from official
   release artifacts, not only from source or saved configuration.
5. Upgrade, reinstall, rollback, and private-state preservation are tested.
6. Production-safe permission, confirmation, backup, custom-code approval,
   validation, audit, and secret-scanning gates are green.
7. The complete CI matrix, release packaging workflow, checksum verification,
   and official archive inspection are green.
8. Maintained installation, update, architecture, capability, security, and
   release documentation is current.
9. A supported public beta has completed a maintainer-approved stabilization
   period without an unresolved release-blocking regression.

The stabilization period is evidence-based, not a fixed calendar promise. The
maintainer records the beta used, observed feedback, resolved blockers, and
remaining known limitations before approving 1.0.

## Mandatory decision record

Before changing versions, tags, GitHub release state, or release workflows, an
agent must present a concise release decision record to the maintainer:

1. **User-visible change:** what users gain or what blocking defect is fixed.
2. **Artifact impact:** which plugin, companion, Visual, schema, updater, or
   documentation artifacts changed.
3. **Recommended action:** no release, supported public beta, preview
   prerelease, or stable release.
4. **Version:** proposed SemVer and why that increment is correct.
5. **Evidence:** local tests, CI, compatibility/runtime proof, package checks,
   and known gaps.
6. **Risk and rollback:** likely failure modes and the exact recovery path.
7. **Documentation:** files that must change with the release.

The agent must make a recommendation rather than merely list possibilities. A
tag, release publication, channel conversion, or stable 1.0 declaration always
requires explicit maintainer approval after this record is shown.

## Automation behavior

The release workflow must accept an explicit release channel instead of
inferring that every beta or release candidate is necessarily a GitHub
prerelease.

The implementation will provide a small validated channel input with these
outcomes:

- `supported` -> GitHub normal release with `--latest`.
- `preview` -> GitHub release with `--prerelease`.
- `stable` -> GitHub normal release with `--latest`, accepted only for a stable
  SemVer version.

Invalid combinations fail closed. In particular:

- `stable` rejects beta and release-candidate versions.
- `preview` rejects stable versions.
- a missing or unknown channel blocks publication.

Tests must cover supported beta, preview beta, preview release candidate,
stable 1.0, malformed versions, missing channels, and invalid combinations.

## Current release transition

`1.0.0-beta.10` is the supported public beta and the recommended installation.
Its existing assets and checksums remain immutable. The GitHub release metadata
will be changed from `Pre-release` to a normal `Latest` release after the policy
implementation is merged and all CI checks pass. No new version or artifact is
created for this metadata-only correction.

## User-facing discoverability

The README header will expose one obvious supported-download path:

- `Current release: 1.0.0-beta.10 - Public Beta`;
- direct plugin ZIP download;
- installation guide;
- companion setup command or direct companion asset link;
- checksum link;
- a short statement that preview builds, when they exist, live on the complete
  Releases page and are not recommended by default.

Evergreen documentation continues to use `VERSION` placeholders. Exact beta.10
links are allowed only in the current-release header while beta.10 is the
supported release; the documentation-freshness contract must explicitly own
and validate this exception so it cannot become a stale hidden pin.

## Application text boundary

Any maintainer application text is prepared outside tracked repository files.
It must use truthful public evidence and must not invent usage, stars,
downloads, security adoption, or community impact. Repository branches,
commits, pull requests, changelogs, release notes, and code comments describe
only product and maintenance changes.

## Verification

Implementation is complete only when:

- the new `AGENTS.md` rules contain the release classes, decision record,
  explicit-approval boundary, and stable gates;
- release-channel tests pass and publication fails closed for invalid input;
- README download links resolve to the supported beta.10 assets;
- documentation freshness and public hygiene pass;
- local package verification passes when workflow behavior changes;
- all pull-request checks are green;
- the change is merged normally into `main`;
- beta.10 is a normal `Latest` release while all existing asset digests remain
  unchanged.
