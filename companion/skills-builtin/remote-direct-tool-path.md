---
{
  "name": "Remote Direct tool path",
  "description": "For remote/live Direct work use remote REST/admin-HTTP only — never local WP-CLI, MySQL, or Local app paths.",
  "triggers": [
    "WP-CLI",
    "local",
    "Direct mode",
    "remote",
    "Application Password",
    "sites.json",
    "php-execute",
    "MySQL"
  ],
  "enabled": true,
  "updated_at": "2026-10-12T00:00:00.000Z"
}
---

# Remote Direct tool path

## Hard rule

If the task target is a **remote/live** site and the session is **Direct mode**:

1. Use Direct tools bound to **that** site (they call core REST with the Application Password: content, taxonomy, ACF, media tools).
2. For admin-only surfaces without REST: authenticated **form POST** / admin-ajax with nonces — not a different host. `stonewright-rest-request` is GET only.
3. **Do not** use local WP-CLI, Local Sites filesystem paths, local MySQL, or plugin-mode tools pointed at a local install to “finish” a remote task.
4. Local WP-CLI success is **not** remote work.

## Preferred order (Direct)

1. Stonewright Direct typed tools (they use core REST + Application Password)
2. Official plugin REST when exposed  
3. WP REST reads with `stonewright-rest-request`
4. Authenticated admin form POST  
5. Browser/Playwright for screenshots or last-resort UI only  

## Plugin mode note

`php-execute` (on the `full` profile), full Elementor engines, and site-hosted skills require the Stonewright **plugin** on that same site — not a local proxy for a remote hostname. On a site where the plugin is active, Direct refuses writes and says to use Plugin mode; do not look for a way around it.
