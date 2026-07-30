---
{
  "name": "No hallucination protocol",
  "description": "Never invent tool inputs, schemas, IDs, or endpoints; fix errors by reading the real message.",
  "triggers": ["error", "failed", "unknown", "api"],
  "enabled": true,
  "updated_at": "2026-07-16T00:00:00.000Z"
}
---

# No-hallucination protocol

1. Never fabricate tool inputs, schemas, IDs, or endpoints.
2. Verify targets exist with a read before any write.
3. A tool error means **read the message** and fix the cause — do not retry the same input more than once.
4. The same error twice → record a learning with `stonewright-learning-record` and change approach.
5. When documentation knowledge is missing, use web search on official docs.
6. Report honestly what Direct mode cannot do (`capabilities.plugin_only` from task-start).
7. Fix `recurring_errors` from `stonewright-task-start` before starting new work.
