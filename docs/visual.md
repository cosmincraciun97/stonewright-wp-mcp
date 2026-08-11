# Stonewright Visual

`visual/` is an AGPL-licensed, headless workspace foundation for WordPress
editors. It exports one top-level MCP contract:
`stonewright-workspace-request`.

Editor-specific tools remain nested behind `workspace_call_page_tool`. This
keeps Elementor and Gutenberg schemas out of the top-level MCP tool list.
Nested `batch_call` supports aliases such as `$hero`, compact summaries,
mandatory mutation readback, and rollback through editor transactions or
per-tool rollback handlers.

Backend tools must come from the Visual-safe discovery contract. Dangerous
tools are hidden by default; writes and elevated calls enter the confirmation
state machine before execution. The dispatcher does not expose a JavaScript
eval method.

```bash
cd visual
npm install
npm run typecheck
npm test
npm run build
```

## Admin host disabled

The former **Stonewright → Visual Workspace** page is not registered in this
release. Direct requests to `admin.php?page=stonewright-visual-workspace` do not
render the product surface, and its page-specific assets are not enqueued. The
headless package remains built and tested because typed MCP design workflows
still use its adapter, transaction, confirmation, and verification contracts.
Persistent design data is preserved; disabling the library is not a data
deletion or a claim that the underlying render engines were removed.

### Adapter detection

Detection runs against the connected editor window, not the separate admin
host. It walks Elementor V4 atomic, then Elementor V3, then Gutenberg. An
editor that is present but cannot be driven stops the walk instead of falling
through to the next candidate — treating an Elementor page as Gutenberg is the
outcome this ordering exists to prevent. A blocked popup, closed editor window,
60-second runtime timeout, or unsupported adapter is a visible connection
error, never a false connected state.

### Write ladder

Every editor write follows read → preview → confirm → apply → verify, enforced
by the controller rather than by the markup:

| Step | What happens |
|---|---|
| Read | The adapter reads the page. Nothing can be proposed before this. |
| Preview | Operations are staged. No editor call is made. |
| Confirm | The confirmation panel states target, breakpoint, before → after, and the direction in force. |
| Apply | The one private dispatch runs, and only from the applying state. |
| Verify | Evidence is summarized. Missing evidence is reported as unverified. |

A proposed operation carries both a human-readable diff and the exact arguments
the editor tool will receive, so the panel shows what a person needs while the
adapter still gets what its own schema requires. Applying with no evidence
behind it does not report success: the change may well have landed, and the
workspace says the claim of correctness failed. That is the same contract
`stonewright/design-quality-check` uses on the server.

### Evidence

The inspector reads the stored quality report for the post through
`design-studio/quality` and renders one row per failing or unchecked rule. A
report counts as verified only when it has findings, none failed, and no rule
went unchecked. Rules with no evidence to run against are listed as unverified
instead of being folded into a pass.

### Accessibility

Both the picker and workspace include a four-step onboarding guide. Contextual
help opens on pointer hover or keyboard focus, uses `role=tooltip`, closes with
Escape, and never replaces a visible control label.
The viewport group is a keyboard-operable toolbar with `aria-pressed` state.
At 1024 px and below the inspector collapses into a drawer with an `aria-expanded`
toggle, `aria-modal` content, Escape to close, and focus returned to the toggle.
There are no `window.confirm()` dialogs; confirmation is the panel described
above. Transitions respect `prefers-reduced-motion`.

### Build and packaging

`plugin/assets/visual/` is generated, not committed. Build it from `visual/` and
stage it before packaging. `scripts/package-verify.mjs` warns when the bundle is
missing from a source checkout and fails under `--require-visual-bundle`, which
CI and the release workflow pass after staging.
