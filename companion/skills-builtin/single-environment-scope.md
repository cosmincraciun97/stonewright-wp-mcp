---
{
  "name": "Single-environment scope",
  "description": "Change only the site/environment the user named. No parallel local/staging edits unless asked.",
  "triggers": [
    "live",
    "production",
    "staging",
    "local",
    "environment",
    "for consistency",
    "also update",
    "mirror changes"
  ],
  "enabled": true,
  "updated_at": "2026-07-16T00:00:00.000Z"
}
---

# Single-environment scope

## Hard rule

When the user names **one** target (URL, alias, “live”, “staging”, “local”):

1. Mutate **only** that environment.
2. Do **not** also change another environment “while I’m here” or “for consistency.”
3. If you already changed the wrong environment by mistake: **report it** and offer restore — never hide it.

## Checks

- Confirm site alias / base URL from task-start / site config before writes.
- If tools for another install are visible (other MCP server, other alias), do not use them for this task.
