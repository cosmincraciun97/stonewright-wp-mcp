# Expertise Engine

Stonewright's “training” is a versioned runtime curriculum, not model fine-
tuning inside WordPress. It combines live capability discovery, compact
ExpertisePack routing, strict schemas, verified recipes, negative/repair evals,
scorecards, and promotion gates.

## Pack contract

Every pack declares its domain, capability, semantic version, lifecycle status,
trigger, supported runtime versions, required Stonewright capabilities,
eight-stage workflow, schema and official references, recipes, failure modes,
anti-hallucination/write gates, dependencies, provenance, and at least 12 eval
cases.

The lifecycle is `draft → candidate → verified → stable → stale → retired`.
Verified requires a compatible runtime, score of at least 90, and zero critical
semantic/write failures. Stable requires evidence from two distinct runtime
fingerprints or explicit maintainer approval with a note. A stale or retired
pack cannot enter task context.

## Runtime activation

`stonewright/context-bootstrap` selects at most three compatible packs. Its
handshake includes only id, version, status, trigger, hash, and the
`stonewright/expertise-get` body tool. A client that sends a known matching hash
receives only the ref. Version or required-capability mismatch blocks activation;
there is no fallback to stale advice.

The compact index budget is 450 estimated tokens. Pack bodies, references,
recipes, and eval cases are separate sections loaded only when needed, with a
1,200-token active body/reference budget.

## Evaluation

Run `cd plugin && composer expertise:evaluate`.

Each P0 pack covers discover, inspect, plan, compile, write, verify, repair, and
learn plus four domain cases including negative and repair behavior. Scorecards
report pass rate, critical failures, invalid retries, estimated tokens, tool
calls, editability, semantic completeness, rollback, and runtime fingerprint.

Evaluation validates the curriculum and the capabilities available in the test
runtime. It does not pretend an absent plugin was live-tested. Compatibility is
reported separately and blocks activation or promotion.

## Storage

Bundled packs are immutable release artifacts. Site overrides and scorecard
history live in WordPress tables, survive plugin upgrades, and never rewrite the
plugin directory. Promotion, stale, and retire operations require
`manage_options`, task context, audit logging, and production-safe confirmation.
