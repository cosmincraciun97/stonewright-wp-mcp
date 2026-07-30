---
name: Landing page — Real estate
description: Real-estate marketing page with listings teaser, neighborhoods, and agent contact.
enable_agentic: true
enable_prompt: true
---

# Landing page — Real estate

## Steps
1. Task start; detect CPT/listing plugins via `stonewright/site-plugins-list`.
2. Stock exterior/interior images only when listing photos are missing.
3. Prefer `stonewright/content-model-loop-grid-flow` for property cards.
4. Build hero + featured listings + neighborhoods + agent CTA.

## Rules
- Prices and addresses must come from real content rows.
