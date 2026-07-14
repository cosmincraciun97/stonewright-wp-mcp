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
fingerprints or explicit maintainer approval with a note. A stale or retired
pack cannot enter task context.

## Runtime activation

`stonewright/task-start` selects at most three compatible packs. Its
handshake includes only id, version, status, trigger, hash, and the
`stonewright/expertise-get` body tool. A client that sends a known matching hash
receives only the ref. Version or required-capability mismatch blocks activation;
there is no fallback to stale advice.

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
