# MCP Token Benchmarks

## Plugin surface + task-start

```bash
cd plugin && composer tokens:measure
```

Hard budgets (non-zero exit on breach):

| Gate | Limit |
|---|---:|
| Essential mode tools | ≤ 30 |
| Default profile cap | ≤ 20 tools |
| Strict / low-tools cap | ≤ 12 tools |
| Bootstrap tools | ≤ 12 |
| `task-start` non-visual compact | < 800 estimated tokens |
| `task-start` visual compact | < 1200 estimated tokens |

Dry-run that must exit `1`:

```bash
php bin/measure-token-surface.php --fixture=over-budget
# or: composer tokens:measure:fixture-over-budget
```

## Companion / Direct tool counts

```bash
cd companion && npm run tokens:measure
```

| Gate | Limit |
|---|---:|
| Plugin bootstrap (proxied) | ≤ 12 |
| Plugin essential (proxied + local) | ≤ 31 |
| Plugin low-tools (proxied + local) | ≤ 12 |
| Direct full (when `src/direct` present) | ≤ 100 |
| Direct essential (when export present) | ≤ 21 |

Dry-run that must exit `1`:

```bash
node scripts/measure-tool-surface.mjs --fixture=over-budget
```

The estimate is compact JSON UTF-8 byte length divided by four and rounded up.
It is a stable regression metric, not a claim that every model tokenizer emits
the same count. Commit a before/after report for changes that affect the public
MCP surface or task-start context.
