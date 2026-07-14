# MCP Token Benchmarks

Run `cd plugin && composer tokens:measure` after changes to abilities, tool
profiles, task bootstrap, skills, specializations, or schemas.

The estimate is compact JSON UTF-8 byte length divided by four and rounded up.
It is a stable regression metric, not a claim that every model tokenizer emits
the same count. Commit a before/after report for changes that affect the public
MCP surface or task-start context.
