# Why Stonewright MCP

A generic WordPress API bridge can expose endpoints to an agent. Stonewright MCP adds a guarded execution and evidence layer around supported changes.

| Concern | Generic WordPress API bridge | Stonewright MCP |
|---|---|---|
| Writes | Generic requests or broad endpoint calls | Typed abilities with bounded schemas and surface-specific rules |
| Elementor | Meta or document payloads supplied by the caller | Live control evidence, surgical mutation, architecture gates, and post-write verification in Plugin mode |
| Backups | Depends on client implementation | Required snapshots before supported Elementor, template, global-style, and theme-backed writes |
| Custom code | Usually a direct privileged write | Provider discovery, dry-run, exact human approval, apply, readback, and rollback |
| Destructive production work | Client-defined confirmation | Scoped confirmation tokens in `production-safe` mode |
| Verification | A successful response may be treated as completion | Typed readback and effect-verification evidence are separate from execution success |
| Repeated failures | Usually remain log entries | Classified incidents, ranked task-start actions, correlated repair receipts, and stale-on-recurrence learning |
| Recovery | External backup or manual reversal | Audit-linked restore and rollback paths for supported surfaces |
| Tool surface | One large static catalog | Compact startup surface plus task-aware working profiles |
| Pluginless mode | Often the default | Explicit Direct capability boundary; Plugin-only controls are not claimed when unavailable |

## Three representative workflows

### Elementor repair

Read the document and live control schema, target one safe root, send one evidence-backed dry-run batch, snapshot before apply, verify touched IDs, then complete desktop/tablet/mobile browser checks. See [Elementor write closure](permanent-remediation-contracts.md#elementor-and-gutenberg-writes) and the [ability matrix](ability-truth-matrix.md).

### Custom-code change

Discover the active provider, read the exact target, dry-run the proposed bytes, present the approval URL and summary, then stop. Apply only after explicit approval; read back and preserve rollback evidence. See [custom-code recovery](security.md#custom-code-and-theme-file-recovery) and [install prompt rules](install-prompts.md#custom-code-approval-rule).

### Repeated-failure handling

Aggregate a normalized cause into an incident, return a compact task-start repair action, correlate the repaired write with an independent verifier, and only then promote a scrubbed lesson. If the same cause returns, reopen the incident and stale the lesson. See [verified learning](verified-learning.md).

## Capability boundaries

Plugin mode supplies the full permission, backup, confirmation, typed Elementor, custom-code approval, site memory, and audit lifecycle. Direct mode supplies a smaller companion-managed REST and WP-CLI surface and local per-site state. Direct mode returns guidance instead of claiming proof when it cannot establish independent correlation.

The [architecture](architecture.md), [security model](security.md), and generated [ability truth matrix](ability-truth-matrix.md) are the source-readable contracts behind these claims.
