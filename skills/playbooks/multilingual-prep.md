---
name: Multilingual prep
description: Prepare pages and content models for multilingual plugins without breaking structure.
enable_agentic: true
enable_prompt: true
---

# Multilingual prep

## Steps
1. Detect language plugins via `stonewright/site-plugins-list`.
2. Inventory pages/CPTs that need translation.
3. Avoid hard-coded language strings in widgets; use content fields.
4. Document slug strategy; do not invent hreflang without plugin support.
5. Use WP-CLI discover for the active multilingual plugin commands.

## Rules
- Never bulk-delete untranslated content.
