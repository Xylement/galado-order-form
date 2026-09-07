# Claude Code Project Graph Guidelines

## Memory & Knowledge Graph Architecture

This project uses **Graphify** mapped directly into a local **Obsidian** vault layout located inside `./graphify-out/obsidian/`. Do not perform expensive global grep commands or dump massive structural source trees directly into your context window.

### Graph Interaction Rules

- **Look up structural mappings** at `./graphify-out/obsidian/` first when looking for code relationships.
- **Reference node connections** (Markdown links `[[note]]`) to understand dependencies, class hierarchies, and cross-file variables.
- Prefer a scoped `graphify query` over reading the vault in bulk — the vault is 1173 notes and is meant to be traversed, not ingested.

### Quick Commands

```bash
graphify update .              # incremental graph refresh after code changes (AST-only, no API cost)
graphify export obsidian       # re-render the Obsidian vault from the current graph
```

Full rebuild from scratch (this repo indexes code-only; see note below):

```bash
graphify . --code-only && graphify export obsidian
```

> **Note:** `graphify . --obsidian` is the in-assistant `/graphify` skill form, not a bare-CLI flag. On the CLI the vault comes from `graphify export obsidian`.
>
> **Code-only:** this graph covers the 101 code files via local AST parsing. 15 docs and 10 images were skipped because semantic extraction needs an LLM API key. To include them, set a key (e.g. `GOOGLE_API_KEY`) and run `graphify .` without `--code-only`. Community names are `Community N` placeholders for the same reason — `graphify label .` names them once a key is set.

## graphify

This project has a knowledge graph at graphify-out/ with god nodes, community structure, and cross-file relationships.

Rules:
- For codebase questions, first run `graphify query "<question>"` when graphify-out/graph.json exists. Use `graphify path "<A>" "<B>"` for relationships and `graphify explain "<concept>"` for focused concepts. These return a scoped subgraph, usually much smaller than GRAPH_REPORT.md or raw grep output.
- If graphify-out/wiki/index.md exists, use it for broad navigation instead of raw source browsing.
- Read graphify-out/GRAPH_REPORT.md only for broad architecture review or when query/path/explain do not surface enough context.
- After modifying code, run `graphify update .` to keep the graph current (AST-only, no API cost).
