# Expertise Engine

Stonewright's “training” is a versioned runtime curriculum, not model fine-
tuning inside WordPress. It combines live capability discovery, compact
ExpertisePack routing, strict schemas, candidate recipes, negative/repair curriculum cases,
scorecards, and promotion gates.

## Pack contract

Every pack declares its domain, P0/P1/P2 tier, capability, semantic version, lifecycle status,
trigger, supported runtime versions, required Stonewright capabilities,
eight-stage workflow, schema and official references, recipes, failure modes,
anti-hallucination/write gates, dependencies, provenance, and at least 12 eval
cases.

The lifecycle is `draft → candidate → verified → stable → stale → retired`.
Verified requires a persisted compatible runtime scorecard, exact fixture and
schema hashes, editor/frontend/readback evidence, a curriculum score of at
least 90, and zero critical failures. Stable requires evidence from two distinct runtime
fingerprints, or one runtime and a note from the approving administrator. A stale or retired
pack cannot enter task context.

Promotion to verified or stable also needs an administrator. The promotion call
stages a request and returns an approval link; an administrator approves or
rejects it on the Skills screen in wp-admin. An approval works once, for that
exact pack and target, and expires after an hour. The `maintainer_approved` and
`approval_note` arguments are ignored.

## Runtime activation

`stonewright/task-start` selects at most three compatible packs. Its
handshake includes only id, version, status, trigger, hash, and the
`stonewright/expertise-get` body tool. A client that sends a known matching hash
receives only the ref. Version or required-capability mismatch blocks activation;
there is no fallback to stale advice.

Each pack declares the runtime versions it describes. The packs for V3
documents (`elementor-v3`, `theme-builder`, and `elementor-pro-dynamic`) accept
Elementor core 3.20 up to, but not including, 5.0, because Elementor 4 still
edits V3 documents beside atomic ones. The atomic pack (`elementor-v4-atomic`)
needs Elementor 4.0 or later. Every bundled pack requires WordPress at the
plugin's own minimum (6.7) or later, and the WooCommerce packs share one
WooCommerce range. A pack names the abilities it needs in
`required_capabilities`; the scoped-snippet pack names
`stonewright/custom-code-provider` (dry run, human approval, rollback) and the
write-recovery pack names the change history and rollback abilities.

The compact index budget is 450 estimated tokens. Pack bodies, references,
recipes, and eval cases are separate sections loaded only when needed, with a
1,200-token active body/reference budget.

The bundled curriculum contains 18 packs and 216 deterministic curriculum
contract cases. Shipped packs without an exact live runtime fingerprint remain
`candidate` and are returned as advisory-only guidance; those cases do not
self-certify implementation behavior. Forms, WooCommerce-builder templates, SEO plugins,
and non-Elementor builders stay `draft` until their runtime exposes a verified
adapter. Runtime discovery reports each known integration as `supported`,
`discovery-only`, or `unavailable`; discovery-only never grants write authority.

## Evaluation

Run `cd plugin && composer expertise:evaluate`.

Each pack covers discover, inspect, plan, compile, write, verify, repair, and
learn plus four domain cases including negative and repair behavior. This first
pass audits curriculum structure. A separate runtime evidence object must name
the task and fixture, bind the exact schema hash, and prove editor, frontend,
and readback verification. Scorecards report curriculum pass rate, critical failures, invalid retries, estimated tokens, tool
calls, editability, semantic completeness, rollback, and runtime fingerprint.

Evaluation never treats curriculum text as implementation proof. Compatibility
and `implementation_verified` are reported separately; promotion to `verified`
is blocked until a qualifying evidence-backed scorecard is persisted.

## Storage

Bundled packs are immutable release artifacts. Site overrides and scorecard
history live in WordPress tables, survive plugin upgrades, and never rewrite the
plugin directory. Promotion, stale, and retire operations require
`manage_options`, task context, audit logging, and production-safe confirmation.
