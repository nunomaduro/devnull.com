# Project setup

A social network for developers. Sign in with GitHub, follow developers, and share commits.

Read the [knowledge base](KNOWLEDGE_BASE.md) for the stack, hosting, repository, and platform lore.

## Getting Started

Requires PHP 8.5+, Composer, Bun, and PostgreSQL 17+ with the [pgvector](https://github.com/pgvector/pgvector) and `pg_trgm` extensions available. [Laravel Herd](https://herd.laravel.com)'s PostgreSQL service ships with both. Create a `devnull` database for the app and a `testing` database for the test suite.

```bash
git clone https://github.com/nunomaduro/dev-null.sh.git
cd dev-null.sh
composer setup
```

The setup script installs dependencies, creates `.env`, generates an app key, runs migrations, and builds assets.

### Local auto-login

To sign in locally without GitHub, set the following in `.env`:

```dotenv
APP_ENV=local
AUTO_LOGIN=true
```

Run `composer dev` and visit `http://localhost:8000` (or your configured `APP_URL`). Auto-login only works in the local environment.

### GitHub login

Leave `AUTO_LOGIN=false` to sign in using GitHub. Create a [GitHub OAuth app](https://github.com/settings/developers) with the callback URL `{APP_URL}/auth/github/callback`, then set `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` in `.env`.

### Embeds

A link card for a video, song or pen becomes a player that loads when clicked. The player URL is built on the server from the link, so only the sites below can be framed. None of them need an API key.

| Key | Links |
|---|---|
| `youtube` | `youtube.com/watch`, `youtu.be`, `/shorts`, `/live`, `/playlist` (with `t=` start times) |
| `vimeo` | `vimeo.com/{id}`, unlisted `vimeo.com/{id}/{hash}`, channels and groups |
| `loom` | `loom.com/share/{id}` |
| `twitch` | channels, `/videos/{id}` and clips |
| `suno` | `suno.com/song/{id}` |
| `spotify` | tracks, albums, playlists, artists, episodes and shows |
| `soundcloud` | tracks and sets |
| `apple_music` | albums, playlists and songs |
| `codepen` | pens |
| `asciinema` | recordings |

| Variable | Default | |
|---|---|---|
| `EMBEDS_ENABLED` | `true` | `false` turns every link back into a plain link card |
| `EMBEDS_PROVIDERS` | empty (all) | Comma-separated keys from the table above, such as `youtube,suno,spotify` |
| `EMBEDS_YOUTUBE_PRIVACY_ENHANCED` | `true` | Plays YouTube from `youtube-nocookie.com` |

Twitch requires the embedding site's host, which is taken from `APP_URL`. To add a site, implement `App\Contracts\Services\EmbedProvider` and register it in `config/embeds.php`.

### Verify installation

Run `composer test` to check linting, static analysis, type coverage, and tests. The code coverage check requires Xdebug or PCOV.

## Public API

Read-only, no authentication, JSON, versioned under `/api/v1`. Only approved commits are returned; pending, rejected, and deleted commits are a 404 or left out. Rate limited to 60 requests a minute per IP, and open to cross-origin `GET` requests.

| Endpoint | Returns |
|---|---|
| `GET /api/v1/users/{username}` | A public profile with commit, follower, and following counts |
| `GET /api/v1/users/{username}/commits` | The user's top-level commits, newest first, 20 per page (`?replies=1` for their replies instead) |
| `GET /api/v1/commits/{id}` | One commit with its author, public parent id, star and reply counts, link preview (with its `embed` player, if any), and images (`id`, `url`, `width`, `height`) |

Lists are cursor-paginated: follow `links.next` (or pass `meta.next_cursor` as `?cursor=`) until it is `null`.

```bash
curl -s https://dev-null.sh/api/v1/users/nunomaduro/commits | jq '.data[] | {short_hash, body}'
```

Every profile also has an Atom feed of its latest 50 top-level commits at `/@{username}/feed.atom`, with their images as enclosures, cached for five minutes.

## Available Tooling

### Development
- `composer dev` - Starts Laravel server, queue worker, log monitoring, and Vite+ dev server concurrently

### Code Quality
- `composer lint` - Runs Rector (refactoring), Pint (PHP formatting), and Oxfmt (JS/TS formatting)
- `composer test:lint` - Dry-run mode for CI/CD pipelines

### Testing
- `composer test:type-coverage` - Ensures 100% type coverage with Pest
- `composer test:types` - Runs PHPStan at the configured maximum level and TypeScript checks
- `composer test:unit` - Runs Pest tests with 100% code coverage requirement
- `composer test` - Runs the complete test suite (linting, static analysis, type coverage, unit tests)

### Maintenance
- `composer update:requirements` - Updates the PHP and frontend dependency version constraints

### Semantic commit search

Commit search generates and caches query embeddings with the Laravel AI SDK, using the existing `OPENAI_API_KEY`. The provider, model, and dimensions are explicitly kept compatible with stored embeddings, so no re-embedding or database migration is needed. Query embeddings are cached for one hour; result visibility and relevance are evaluated on every search.

PostgreSQL cosine ranking, visibility filters, and the existing OpenAI relevance classifier are unchanged. Batch ingestion continues through the existing OpenAI service to preserve response-index ordering and count validation. Moderation is unchanged. After installing dependencies, run `php artisan boost:update` to refresh agent guidance and the AI SDK skill (run `php artisan boost:install` first if Boost is not configured locally).

### Knowledge base embeddings

After running the database migrations and configuring `OPENAI_API_KEY`, import the Markdown knowledge base:

```bash
php artisan knowledge-base:embed
```

The command defaults to `KNOWLEDGE_BASE.md`. Pass another repository-relative Markdown path to import a separate file.

Each non-empty section becomes a row in `knowledge_base_sections`, storing its source path, position, heading hierarchy, Markdown content, and vector embedding. All heading levels create boundaries; parent headings accompany child sections in the embedding input. Empty headings create no rows, and headings inside code blocks or blockquotes do not split sections.

Rerun the command after editing a file. It uses the existing OpenAI service to embed only new or changed sections, reusing vectors when both content and heading hierarchy are unchanged, even if a section moved. It atomically updates or inserts rows and removes sections no longer present. Other files are left alone; a failed import preserves the previous data. The reported count is the number of newly generated embeddings. Unchanged imports make no paid requests. Keep sections small enough for the embedding model's input limit; there is no additional size-based splitting. This prepares knowledge for retrieval without implementing Tom's planned DeepSeek bot.

#### Automatic production updates

The **Knowledge base embeddings** GitHub Actions workflow runs in the upstream `nunomaduro/dev-null.sh` repository when a push to `production` changes `KNOWLEDGE_BASE.md` (including a merged pull request). Changes to the workflow or its script also trigger it. It does not run on pull requests or forks.

One-time setup in the upstream repository's GitHub `production` environment (or repository Actions settings):

- Add the **secret** `LARAVEL_CLOUD_TOKEN`, a Cloud API token permitted to inspect the production environment and run commands there.
- Add the **variable** `LARAVEL_CLOUD_ENVIRONMENT_ID`, the ID of the Cloud environment deploying this repository's `production` branch.
- Ensure Cloud push-to-deploy is enabled, the normal deploy commands run migrations, and `OPENAI_API_KEY` is configured in Cloud. Database credentials and the OpenAI key stay in Cloud, not GitHub Actions.
- If the GitHub `production` environment requires manual approval, the job waits for that approval rather than running unattended.

The job waits up to twenty minutes for the merged commit, or a newer descendant, to be live. It then runs the importer inside Cloud, using a hash of the deployed Markdown to reject a mismatched release. Runs are serialized without cancelling an in-progress import. It never starts a deployment or runs migrations itself. A deployment timeout, API error, hash mismatch, or failed import fails the job; rerun the failed job or use **Run workflow** on `production` to retry safely. The importer skips sections already up to date.

Automatic imports currently cover `KNOWLEDGE_BASE.md` only. If the knowledge base is split into more files, extend the workflow's paths and import script together.

## License

Licensed under MIT, as declared in `composer.json`. Based on [Nuno Maduro's Laravel starter kit](https://github.com/nunomaduro/laravel-starter-kit-inertia-react).
