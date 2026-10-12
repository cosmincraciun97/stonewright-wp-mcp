---
name: Section refactor
description: Refactor a single page section for spacing, hierarchy, and builder-native structure without full rebuild.
enable_agentic: true
enable_prompt: true
---

# Section refactor

## Steps
1. Identify target section via structure tools (`elementor-v3-get-page-structure` or block parse).
2. Snapshot the post (`stonewright/site-backup-page`).
3. Prefer batch mutate over full rebuild: `stonewright/elementor-v3-batch-mutate` (run `dry_run` first; pass `expected_tree_hash` from the read) or `stonewright/blocks-batch-mutate` / `blocks-update` for Gutenberg.
4. Re-read structure; verify spacing tokens and heading levels.

## Rules
- Touch only the requested section.
