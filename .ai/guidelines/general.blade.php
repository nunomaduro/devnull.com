# General Guidelines

- Don't include any superfluous PHP Annotations, except ones that start with `@` for typing variables.
- Every time you create a new file, stage it immediately with `git add <path>`. Never commit; leave committing to the user.
- Do not write browser tests (`tests/Browser`, Pest browser plugin, Playwright). Cover behavior with Feature and Unit tests only.
- Use the Laravel AI SDK for new AI features and semantic search query embeddings. Load the `ai-sdk-development` skill and keep the embedding provider, model, and dimensions compatible with the stored vectors.

## Knowledge base maintenance

- Before finishing any change, check whether `KNOWLEDGE_BASE.md` needs updating to reflect it. Update the relevant sections in the same change when stack, hosting, repository details, platform behavior, or lore have changed; leave it alone when they have not.
- Keep technology descriptions free of version numbers, distinguish planned features from shipped behavior, and verify names, handles, and historical claims rather than guessing.
- Keep topics under descriptive Markdown headings: each non-empty section is embedded separately, with its parent headings providing context.
- Production CI refreshes embeddings after knowledge-base changes are deployed. Do not manually run production embedding imports as part of an unrelated change.
