# Quality artifacts

Durable reasoning artifacts for product decisions. Not consumer documentation —
the docs site lives in `docs/`, and this directory is `export-ignore`d like
`specs/`.

- **`personas.md`** — who consumes richter's output, with the failure each
  persona fears. A persona earns its place only when it changes what richter
  must output.
- **`stories.md`** — persona-tagged user stories, keyed `RICH-NNN`. The durable
  contract of *what should be true* for each use of the tool.
- **Decision documents** (for example `two-tier-introspection.md`) — a proposed
  change evaluated against the stories, ending in a verdict.

When a proposal arrives, run it through the stories: for each story, does the
proposal change the outcome for that story's personas? A proposal that changes
no outcome is not built.
