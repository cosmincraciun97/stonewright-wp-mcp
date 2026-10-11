# Elementor MCP with Stonewright

Elementor ships an MCP module of its own. This page says what it is, how to turn
it on, how Stonewright uses it, and which one to connect to your AI client.

## What it is

Elementor's MCP module registers abilities named `elementor/*` through the
WordPress Abilities API (for example `elementor/manage-default-styles`,
`elementor/build-composition` and `elementor/get-page-structure`). Elementor can
publish them on an MCP server of its own, so an AI client could call Elementor
directly. Elementor 4.3 bundles the MCP adapter and the Composer package the module
needs; no separate plugin is required.

Elementor has announced V3 support for its MCP. Stonewright certifies an ability
only after it has checked its exact schema against a contract file, so what runs
natively today is the set in
[Native Elementor abilities](elementor-v4-engine.md#native-elementor-abilities)
(Elementor 4.3.x, Atomic documents). V3 documents stay on the Stonewright V3
writers until a V3 contract is verified.

## Turn it on

1. Use Elementor 4.3.x, the range Stonewright has verified, with the Atomic Editor
   experiment active.
2. In wp-admin open **Elementor** and turn on **Elementor MCP**. The switch is the
   `elementor_mcp_enabled` option and is off by default; with it off, Elementor
   registers no `elementor/*` abilities.
3. Open the **Elementor provider discovery** card on **Stonewright > Setup >
   Troubleshoot**, or ask the agent for `stonewright-elementor-provider-discovery`.
   The native Elementor state (`native_elementor.state`) should be `available`.

| `state` | Meaning and next step |
|---|---|
| `not_installed` | Elementor is not active. |
| `module_unavailable` | This Elementor build has no MCP module. Update Elementor. |
| `requirements_missing` | The Abilities API, the MCP adapter or Elementor's MCP Composer is missing. `native_elementor.mcp_module.missing` names it. |
| `exposure_disabled` | The Elementor MCP switch is off. Turn it on (step 2). |
| `no_abilities_registered` | The module is active but registered nothing yet. |
| `available_uncertified` | Elementor abilities exist, but none matches a Stonewright contract (usually an Elementor version outside the verified range). `guidance` and `version_issue` say which. Native writes are refused. |
| `available` | At least one ability is certified. |

Without `available` nothing is lost: the Stonewright V4 and V3 writers do the same
work, and `stonewright-elementor-native-execute` answers
`stonewright_native_unavailable` with the state and the writers to use.

## How Stonewright wraps it

Stonewright does not pass the AI client through to Elementor's server. It runs the
certified Elementor ability itself, in-process, through
`stonewright-elementor-native-execute`:

- it plans first (`dry_run` defaults to `true`); reads and dry runs need no token in
  any mode, and a real write needs a confirmation token in production-safe mode;
- a real write takes a snapshot of the page or kit (default styles included), holds
  the per-post write lock, reads the result back and compares it with the plan, and
  rolls back on any mismatch;
- the write lands in the change history and the audit log, and the CSS step goes only
  through `stonewright-elementor-css-regenerate`;
- a change to a published page is staged in an autosave and reported as
  `staged_in_autosave`, never as applied;
- abilities that clear generated CSS for the whole site (`manage-classes`,
  `manage-global-variable`, `manage-elements`) are refused with
  `upstream_global_clear_cache`; the Stonewright class and variable writers cover
  them.

The full closure is in [Native execution](elementor-v4-engine.md#native-execution).

## Connect only Stonewright

Connect only Stonewright to the AI client, and do not add Elementor's own MCP server
next to it. A call made on Elementor's server skips the plan, the confirmation, the
write lock, the readback and the rollback. Agents are told to prefer
`stonewright-elementor-native-execute` and never to call `elementor/*` tools on
Elementor's server.

If a call still reaches Elementor's ability from outside Stonewright, an observer
(`observed_native`) snapshots the affected post or kit before it and records a
change-history row and an audit row after it. It never blocks or changes the call,
and it is a safety net: it does not add the plan, the confirmation or the readback.

Turning the Elementor MCP switch on does not change what the AI client sees. The
client keeps one connection, to Stonewright, and Stonewright decides which Elementor
abilities it may run.

## See also

- [Elementor V4 engine](elementor-v4-engine.md#native-elementor-abilities): contracts,
  certified versions and refusals
- [Elementor V4 (experimental)](elementor/v4-experimental.md)
- [Rescue](rescue.md#change-history-ledger): the change history that records these writes
