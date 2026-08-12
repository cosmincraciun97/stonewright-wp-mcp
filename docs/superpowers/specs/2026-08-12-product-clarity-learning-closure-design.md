# Stonewright Product Clarity and Verified Learning Closure Design

**Date:** 2026-08-12

**Status:** Approved direction; implementation specification

**Scope:** Repository product presentation, onboarding, release metadata, and the Plugin/Direct incident-to-learning lifecycle
**Out of scope:** Public website, npm publication, testimonials, pricing, customer data, and new WordPress feature breadth

## Objective

Make Stonewright MCP easier to understand and install while turning its existing
audit, incident, repair, and memory pieces into one truthful closed loop:

```text
failure -> classified incident -> task-aware repair action -> correlated verified repair
        -> resolved incident -> durable lesson -> future task-start guidance
```

The product claim is not autonomous perfection. The claim is stronger and
defensible: Stonewright detects repeated operational failures, stops blind
retries, guides the agent toward a repair, proves whether the repair worked, and
reuses only verified lessons.

## Product Position

Public name:

> **Stonewright MCP**

Category line:

> Guarded, recoverable WordPress and Elementor automation for AI agents.

Primary value statement:

> Your AI does not just change WordPress. Stonewright proves what changed and
> gives you a way back.

Supporting statement:

> A compact task-aware MCP surface backed by 360 WordPress capabilities,
> verified writes, audit evidence, and recoverable workflows.

Avoid headline emphasis on raw tool counts. Counts remain technical proof, not
the first product promise. Avoid an unqualified `safe` claim. Use `guarded`,
`verified`, `recoverable`, and precise descriptions of the gates Stonewright
actually enforces.

## Current State and Verified Gaps

Stonewright already has the correct foundations:

- every supported mutation records a redacted audit event;
- `IncidentStore` tracks observing, open, resolved, suppressed, and reopened
  lifecycle states;
- repeated errors are grouped and exposed by the Audit Log;
- `stonewright-task-start` returns the three highest recurring failures and the
  `fix_recurring_errors_first` action;
- a second identical Plugin error is escalated to a hard stop;
- Plugin learning is promoted only when a verified result contains a concrete
  `repair_recipe`;
- Direct mode records redacted JSONL audit rows, detects recurring failures,
  stops repeated calls, and exposes them at task start;
- explicit user corrections can be persisted and read back in both modes.

The loop is incomplete:

1. Production Plugin abilities do not currently emit `repair_recipe`; only tests
   exercise automatic promotion.
2. `ErrorPatterns::observe_verified_repair()` may associate a success by ability
   name when both the failure and success lack correlation fields. This is too
   permissive for durable learning.
3. `IncidentStore` and `ErrorPatterns` maintain overlapping lifecycle concepts,
   allowing display, resolution, and learning state to drift.
4. Task start says to fix recurring errors but does not return one explicit,
   machine-readable repair workflow and required verification evidence.
5. Direct mode has recurring-error guidance but no equivalent resolved-incident
   and verified-repair promotion lifecycle.
6. Public documentation says `self-improving` without clearly distinguishing
   user corrections, unresolved incidents, verified repairs, and built-in rules.

## Architecture

### 1. Canonical incident lifecycle

`IncidentStore` becomes the canonical lifecycle source in Plugin mode.
`ErrorPatterns` remains a bounded aggregation/index used for recurrence counts,
repair hints, and backwards-compatible UI data; it must not independently decide
that an incident is resolved or that a lesson is promotable.

Canonical incident states stay small and compatible with the current admin UI:

```text
observing -> open -> resolved
resolved -> open (increment reopened_count on correlated recurrence)
open -> suppressed (operator action only)
```

Repair and learning are separate evidence fields, not extra incident states:

```text
repair_phase: none -> proposed -> attempted -> verified
learning_status: none -> promoted -> stale
```

State and phase transitions require persisted audit evidence. Generic success
never closes an incident. A security block is tracked separately and never
becomes active learning.

### 2. Strict repair correlation

A repair may resolve an incident only when all required conditions hold:

- the successful event has `outcome=SUCCESS`;
- `verification_status=verified` and `effect_verified=true`;
- failure and success share a non-empty `change_set_id`;
- failure and success share a non-empty `resource_key_hash`;
- `normalized_path` matches exactly;
- the expected verifier, when recorded, matches the verification event;
- the success event is newer than the incident's last failure.

Optional `cause_fingerprint` and `strategy_fingerprint` strengthen correlation
but never replace the required transaction/resource/path keys. Missing required
keys fail closed: incident remains open and no lesson is created.

Legacy incidents without sufficient keys remain visible. They can be suppressed
by an operator or resolved after a newly observed, fully correlated attempt; they
are never guessed closed.

### 3. Verified repair receipt

Introduce one normalized receipt contract shared by Plugin and Direct mode:

```json
{
  "incident_id": "sha256",
  "resolution_event_id": "uuid",
  "verification_status": "verified",
  "effect_verified": true,
  "change_set_id": "opaque-transaction-id",
  "resource_key_hash": "sha256",
  "normalized_path": "typed/resource/path",
  "repair_recipe": "Read the live schema, replace only the rejected value, then verify the saved control.",
  "repair_scope": "ability-family-or-error-code",
  "evidence": {
    "after_sha256": "sha256",
    "verifier": "stonewright/example-verify"
  }
}
```

`repair_recipe` is concise, actionable, scrubbed, and project-agnostic. It may
not contain URLs, usernames, post IDs, filesystem paths, credentials, raw
payloads, or customer text. Recipes over the bounded length are rejected rather
than truncated into ambiguous instructions.

Typed abilities may include this receipt after a successful repair workflow.
For workflows where the repair and verifier are separate abilities, add a typed
`stonewright/incident-repair-record` ability. It accepts the incident identifier,
resolution event identifier, and proposed recipe, then reads both persisted audit
events and verifies correlation server-side. Agent-provided claims are not proof.

The ability is a write because it changes incident/learning state. It uses the
normal permission callback, task context token, audit, and production-safe
confirmation rules. It does not execute the repair itself.

### 4. Controlled learning promotion

Promotion happens only after a verified repair receipt passes:

- correlation verification;
- recipe scrubber;
- minimum specificity check;
- source allowlist (`verified-repair` or explicit `user-correction`);
- readback of the stored memory row.

Promoted memory stores:

- incident and resolution event hashes;
- ability family and normalized error code;
- generic repair recipe;
- verification timestamp and verifier;
- scope and precedence;
- source `verified-repair`;
- state `promoted_learning`.

Unresolved failures never populate correction/lesson fields. Successful writes
without a concrete recipe may resolve a correlated incident but teach nothing.
The same verified repair is idempotent and creates one active lesson.

If a promoted repair later fails for the same correlated cause, the incident
returns to `open`, `reopened_count` increments, and the lesson becomes `stale`;
it is removed from active task-start guidance until a new repair is verified.

### 5. Task-start repair contract

`stonewright-task-start` returns at most three relevant incidents, ranked by:

1. same requested surface;
2. same ability family;
3. open before observing;
4. severity;
5. recurrence count;
6. recency.

Each compact incident action contains:

```json
{
  "incident_id": "sha256",
  "state": "open",
  "ability": "stonewright/example-write",
  "error_code": "stonewright_example_invalid",
  "occurrences": 3,
  "repair": "Read the typed schema and correct the rejected field.",
  "next_tool": "stonewright/example-schema",
  "required_verifier": "stonewright/example-verify",
  "retry_policy": "repair_then_retry_once",
  "learning_policy": "promote_only_after_verified_repair"
}
```

`required_actions` uses `repair_open_incidents_first`, replacing the vague
`fix_recurring_errors_first`. Built-in instructions tell agents to inspect the
incident action, perform the typed repair, verify it, then record the verified
repair when a generic recipe is known. They never instruct the agent to clear or
dismiss history merely to unblock a task.

### 6. Direct mode parity

Direct mode gains a small local incident store under the existing private
Stonewright state directory. It contains hashes, classification, lifecycle, and
scrubbed recipes only; no secrets or raw payloads.

Direct mutations produce the same correlation fields wherever the typed tool can
identify a resource. Direct task start returns the same compact incident-action
shape. A local verified-repair recorder validates the Direct audit events before
promoting a local lesson. Plugin and Direct memory remain separate and are never
silently copied between sites or machines.

When a Direct tool cannot produce strong correlation or an independent verifier,
the incident remains guidance-only. Stonewright must report this limitation
instead of claiming autonomous learning.

## Repository Presentation and Onboarding

### README information order

The root README first screen becomes:

1. Stonewright MCP name and defensible value statement;
2. one short product paragraph;
3. install/download/security links;
4. current dashboard screenshot;
5. `How it works` proof chain;
6. four-step Plugin Quick Start;
7. three concrete use cases;
8. `Why Stonewright` evidence;
9. capability counts and advanced mode details.

The first paragraph must not lead with Direct mode, package URLs, profile names,
or registry internals. Those remain available under advanced sections and linked
guides.

### Four-step default path

```text
1. Install and activate the Plugin ZIP.
2. Open Stonewright -> Setup and connect the AI client.
3. Restart the client and run connection verification.
4. Start the first task with stonewright-task-start.
```

OAuth is the recommended default for compatible remote clients. Versioned
companion installation, Direct mode, aliases, profiles, browser consent, and
local WP-CLI remain advanced paths. No capability is removed.

### Repository use cases

Add three evidence-based use cases with linked technical proof:

1. **Repair an Elementor section without blind document rewrites** — live schema,
   typed batch mutation, backup, readback, and browser verification.
2. **Change WordPress code with an approval boundary** — discover, dry-run,
   approval, atomic apply, readback, and rollback.
3. **Stop repeating a failed operation** — audit classification, incident action,
   verified repair, and reusable lesson.

Use synthetic examples only. No customer/project names, production screenshots,
or claims based on private deployments.

### Trust and comparison document

Create `docs/why-stonewright.md`, comparing operating models rather than naming
competitors:

| Raw API access | Generic MCP adapter | Stonewright MCP |
|---|---|---|
| Access primitives | Tool wrapping | Typed workflows with evidence |
| Caller-managed safety | Adapter-dependent | Permission, backup, confirmation, validation, audit |
| Best-effort success | Tool response | Readback and verifier receipts |
| Manual failure context | Generic errors | Incident lifecycle and verified lessons |

Claims link to public contracts, generated ability matrix, security model, and
tests. Do not claim absolute safety, universal client certification, autonomous
repair, or parity between Plugin and Direct mode.

### Controlled learning document

Create `docs/verified-learning.md` explaining four distinct sources:

- built-in product rules;
- explicit user corrections;
- unresolved incidents and repair hints;
- promoted verified repairs.

State clearly that an error is evidence of failure, not a lesson. Include the
correlation and stale-learning rules. Link this document from README, PRODUCT,
plugin README, companion README, and relevant install/task-start guides.

## License Metadata

Root `LICENSE` becomes the canonical unmodified AGPL-3.0-or-later license text
so GitHub and package scanners can identify it. Component exceptions remain
explicit:

- Plugin and Stonewright Visual: AGPL-3.0-or-later;
- Companion: MIT from `companion/LICENSE`;
- third-party files: their recorded compatible licenses and SPDX headers.

Add a concise `LICENSING.md` explaining the multi-license layout and linking the
component files. Update README badges/links and add an automated metadata check
that the root license matches the canonical AGPL text. No component license is
changed by this reorganization.

## Beta Release Semantics

Beta tags should appear as GitHub prereleases, but the native updater must still
find the supported beta before this flag changes.

Implement channel-aware release discovery:

- stable plugin versions select the latest non-draft, non-prerelease stable
  release;
- beta plugin versions select the highest compatible non-draft prerelease;
- exact version metadata and asset name must match before offering an update;
- cached release state includes the selected channel;
- failures keep the installed version and never fall back across channels.

After updater tests pass, release workflow derives `--prerelease` from a SemVer
prerelease suffix and does not mark beta tags as repository `latest`. Existing
beta.9 history is not rewritten. This applies to future releases.

## Security and Privacy Constraints

- No customer name, hostname, username, alias, ID, memory row, audit payload, or
  private screenshot enters source, tests, docs, commits, PRs, or release assets.
- No raw audit payload becomes learning.
- Audit and learning stores remain redacted and bounded.
- Expected safety blocks and authentication failures never become agent lessons.
- No incident is deleted automatically after resolution.
- No repair is executed merely because an incident exists.
- No new telemetry, remote service, account, dependency, or network destination.
- Existing permission, backup, confirmation, approval, validation, audit,
  readback, and rollback gates remain intact.

## Testing Strategy

### Plugin

Add unit and integration tests proving:

- missing correlation never resolves an incident;
- unrelated success on the same ability never resolves or teaches;
- exact correlated verified success resolves once;
- verified repair with scrubbed recipe promotes one active lesson;
- customer-like URLs, IDs, paths, and secret-like values block promotion;
- recurrence after promotion reopens the incident and stales the lesson;
- expected blocks and auth events never promote;
- task start ranks relevant open incidents and returns exact repair actions;
- legacy uncorrelated incidents remain visible and unlearned;
- learning write/readback failure leaves the incident resolved but unpromoted;
- release-channel selection distinguishes beta and stable safely;
- root license metadata gate passes.

### Direct companion

Add tests proving:

- Direct failures aggregate into the local incident store;
- second identical call stops with repair action;
- task start exposes the shared compact shape;
- only correlated verified audit events resolve and promote;
- unsupported correlation reports guidance-only state;
- aliases/sites cannot read or promote another site's incidents;
- state survives companion restart and update fixtures;
- package contains no runtime incident/audit/memory data.

### Documentation and packaging

- regenerate the ability matrix when the public surface changes;
- run docs freshness and link checks;
- run public-hygiene scans against source and all archives;
- verify README Quick Start matches the Setup UI and CLI contract;
- verify GitHub release fixtures classify future beta tags as prereleases;
- run the full PHP matrix, quality gates, companion suite, Visual suite,
  production package checks, and wp-env Playwright E2E.

## Delivery Structure

Use one topic branch and one PR, but commit in reviewable product units:

1. canonical Plugin incident correlation and verified repair receipt;
2. Plugin task-start/action and learning lifecycle;
3. Direct incident and verified-learning parity;
4. repository product narrative and four-step onboarding;
5. license metadata and future beta release semantics;
6. generated docs, package proof, and final release notes.

Commit and PR titles describe product behavior, not promotional intent. Suitable
language includes `incident repair lifecycle`, `verified learning`, `onboarding`,
`product documentation`, `license metadata`, and `release channels`.

## Acceptance Criteria

Work is complete when:

1. Plugin and Direct task start expose actionable, ranked incident repair data.
2. An uncorrelated success cannot resolve an incident or create learning.
3. A correlated verified repair can create exactly one scrubbed durable lesson.
4. A later recurrence reopens the incident and disables the stale lesson.
5. README explains the product and default setup before advanced architecture.
6. Three public use cases link claims to repository evidence.
7. GitHub-compatible AGPL metadata and component license boundaries are tested.
8. Future beta releases are prereleases without breaking beta update discovery.
9. No website or npm package is created or published.
10. Full CI and release-package verification remain green.
