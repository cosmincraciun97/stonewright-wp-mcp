# Verified learning

Stonewright separates a repeated failure from a reusable lesson. An audit error can open an incident and produce a repair action, but it cannot teach future agents merely because a later call returned success.

## What becomes learning

Stonewright accepts two evidence classes:

1. **Explicit user corrections** may be recorded immediately with `stonewright-learning-record`. The source and scope remain visible so user direction outranks inferred guidance. The MCP connect-time instructions and the default compact `stonewright-task-start` (`context.learning`) both tell the agent to call it when the user corrects it or a mistake repeats; a lesson counts only when the receipt carries `verified:true`.
2. **Audit-derived repairs** become active learning only after a persisted failure and a later independent verifier are strictly correlated through the same resource and change set. Record that closure with `stonewright-incident-repair-record`.

Generic success, an agent-supplied `verified` flag, an unrelated read, or a recipe without persisted proof does not close the incident.

## Lifecycle

```text
failure -> observing -> repeated failure -> open incident
open incident -> repair -> independent typed verifier -> verified receipt
verified receipt -> resolved incident -> promoted lesson
same cause recurs -> reopened incident -> stale lesson
```

The incident remains the lifecycle authority. The memory row is a derived, reusable result. Reopening never deletes history: it marks the promoted lesson stale so task start stops presenting it as active guidance.

An incident covers one cause: the same error code from the same ability family on the same kind of resource. Incidents that do not involve writes, verification, or rollback close after seven days without a new occurrence, and a recurrence reopens the incident and counts the reopening. Write, verification, and rollback incidents close only through a verified repair.

## Proposed lessons

When the same error repeats ten times, Stonewright adds a proposed lesson to Memory as a draft. A draft is not active. An administrator approves or discards it on the Memory page; approval makes the lesson active and records who approved it and when (UTC). An approved lesson is offered to agents like any other active reference entry; a draft, or a lesson that is active without a recorded approval, is not. A one-time repair returns any proposed lesson that is active without a recorded approval to draft.

## Task-start response

`stonewright-task-start` returns at most three ranked `incident_actions`. When any are actionable, `required_actions` contains `repair_open_incidents_first`.

```json
{
  "incident_id": "<incident-hash>",
  "state": "open",
  "ability": "stonewright-content-update",
  "error_code": "write_failed",
  "occurrences": 2,
  "repair": "Read the persisted failure and correct its normalized cause.",
  "next_tool": "stonewright-content-get",
  "required_verifier": "stonewright-content-get",
  "retry_policy": "repair_then_retry_once",
  "learning_policy": "record_only_after_verified_repair"
}
```

## Plugin mode

The Plugin recorder reads the incident and both audit events from server-side storage. It validates the receipt, resolves the canonical incident, writes one scrubbed `verified-repair` memory row, reads it back, and links it to the incident. Production-safe state changes retain the normal permission and confirmation gates.

The public ability contract is listed in the [ability truth matrix](ability-truth-matrix.md). Audit and incident invariants are defined in [permanent remediation contracts](permanent-remediation-contracts.md).

## Direct mode

Direct incidents live in the companion's private per-site state directory. Rows contain bounded classifications and hashes, not raw arguments, URLs, credentials, or customer text. Writes use restrictive permissions and atomic replacement; different site bindings cannot read each other's incident files.

Direct mode applies the same proof rule when its local audit contains an independent verifier tied to the same change set and resource. If that evidence is unavailable, the recorder returns `guidance_only: true`, leaves the incident open, and creates no lesson. Direct mode never invents Plugin-grade proof from a REST response.

## Repair recorder

Use the typed MCP tool after the verifier has written its audit event:

```json
{
  "incident_id": "<incident-hash>",
  "resolution_event_id": "<verifier-event-uuid>",
  "repair_recipe": "Correct the normalized field mapping, then verify through the typed read tool.",
  "repair_scope": "content writes"
}
```

The recipe must be reusable and free of credentials, URLs, identities, local paths, raw payloads, and site-specific values. A valid response exposes receipt and memory identifiers without returning raw audit events.

## Operational rule

Stonewright learns explicit user corrections immediately, but audit-derived rules only after a correlated verified repair.
