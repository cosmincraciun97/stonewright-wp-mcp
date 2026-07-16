---
{
  "name": "HTTP-first WordPress automation",
  "description": "Automate wp-admin via REST and form POST+nonces; Playwright click/fill is last resort.",
  "triggers": [
    "Playwright",
    "admin click",
    "fill form",
    "CPT UI",
    "ACF",
    "form POST",
    "nonce",
    "admin-ajax",
    "wp-admin"
  ],
  "enabled": true,
  "updated_at": "2026-07-16T00:00:00.000Z"
}
---

# HTTP-first WordPress automation

## Hard rule

Do **not** drive wp-admin with Playwright click/fill as the default. Clicks are brittle (wrong select values, hidden submits, accidental wrong CPT).

## Preferred stack

1. **WP REST** + Application Password  
2. **Official plugin REST/APIs** when available  
3. **Stonewright typed tools** (Direct or plugin mode)  
4. **Admin form POST** with session cookies: GET page → extract nonces → POST fields  
5. **Playwright**: screenshots / visual QA; UI clicks only if no HTTP path works  

## CPT UI specifics

- Create: **Add New** only (additive).  
- Edit: always send `cpt_original` + `cpt_type_status=edit`.  
- Delete/select: never trust the default selected type — select target slug first and verify the name field before POST.  
- Never full Import to add one type (replaces entire option).  
