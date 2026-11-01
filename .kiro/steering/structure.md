---
inclusion: always
---
# Structure

```
.kiro/          specs, steering, hooks, settings/mcp.json (no secrets)
public/         pages, js, css (Observe, Replay, Compare, Admin)
api/            PHP entry points (routing only)
src/            World, Disaster, Resident, Prompt, Recorder, Ai, Memory, Db
mcp/            read-only memory MCP server
sql/            table definitions
tests/          PHPUnit and property-based tests
docs/en/        English brief and published situation texts
pilot/          experiment-0 prototypes made with Claude before Kiro; reference only, not the product
```

- Keep the logic in `src/` as plain PHP classes with no I/O, so it can be tested without a server.
- `api/` only parses requests, calls `src/`, and writes JSON with `Cache-Control: no-store`.
- Japanese source documents stay in the repository root; do not edit them.
