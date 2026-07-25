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

## Visual Workspace admin page

The same workspace runs in the browser under **Stonewright → Visual Workspace**
(`admin.php?page=stonewright-visual-workspace`). The page exists so a person can
see and drive the ladder an MCP client would otherwise walk alone. It requires
`edit_posts`, and pointing it at a post additionally requires
`Permissions::can_edit_post()` for that post.

Open it with `&post_id=<id>`, or use the picker on the page when no post id is
supplied. The requested editor can be pinned with `&editor=elementor-v3`,
`&editor=elementor-v4`, or `&editor=gutenberg`; the default `auto` leaves it to
detection, which is the honest default because only the browser can see which
editor actually loaded.

PHP renders the chrome — heading, target post, expected editor, canvas,
inspector — and the browser bundle fills three slots: the adapter chip, the
canvas body, and the inspector body. If the bundle is not present the page says
so and prints the command that builds it, rather than showing an empty frame.

### Adapter detection

Detection walks Elementor V4 atomic, then Elementor V3, then Gutenberg. An
editor that is present but cannot be driven stops the walk instead of falling
through to the next candidate — treating an Elementor page as Gutenberg is the
outcome this ordering exists to prevent. The admin screen is not an editor
screen, so "no supported editor was found on this page" is a normal reported
state; the stored quality report is still fetched and shown so the page can say
what was last observed.

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
