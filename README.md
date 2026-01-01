# Kontrola / WordPress self-hosted stack

This repo is:

1) a **production-friendly WordPress Docker Compose stack** (WordPress + MySQL + phpMyAdmin + WP‑CLI) designed to run alongside other services on a **shared Docker network**, and
2) the **foundation repo** for the planned **Kontrola AI marketing platform** (see `Kontrola-blueprint.md`).

Today, the “Kontrola platform” pieces in this repo are **early wiring/scaffolding** (REST routes + an optional sidecar “agent” service). They’re meant to prove the integration points, not ship a full SaaS yet.

## What runs (big picture)

Defined in `docker-compose.yml`:

- `wp-db` (MySQL) → persisted in `./data/wp-db`
- `wp` (WordPress) → served at <http://localhost:8888>, persisted in `./data/wp`
- `wp-cli` (WP‑CLI) → admin commands against the same WordPress volume
- `pma` (phpMyAdmin) → served at <http://localhost:8081>

All services attach to the **external** Docker network `shared_network`.

## Setup

1. Create the shared network (once per Docker host):

```bash
docker network create shared_network
```

1. Create your `.env` from the template:

```bash
cp .env.example .env
```

1. Start the stack:

```bash
docker compose up -d
```

### Troubleshooting: Docker engine not reachable

If you see an error like:

- `failed to connect to the docker API at unix:///var/run/docker.sock ...`

…then the **Docker client is installed**, but the **Docker engine/daemon is not running** or is not reachable from your current shell.

Common fixes on Windows:

- Make sure **Docker Desktop** is running.
- If you’re using **WSL**, enable Docker Desktop’s **WSL integration** for your distro.
- Re-open your terminal after enabling integration.

Note: `docker compose config` can work without the daemon (it just renders YAML), but `docker compose up` requires a running engine.

### Windows note

The helper scripts (`backup.sh`, `restart-docker.sh`, `update-docker.sh`) are bash scripts. On Windows, run them via **WSL** or **Git Bash**.

## Day-to-day commands

- Start: `docker compose up -d`
- Stop: `docker compose down`
- Restart: `./restart-docker.sh`
- Update images + restart: `./update-docker.sh`
- Backup DB (writes to `./backups/`): `./backup.sh`

## Reverse proxy

`config/Caddyfile` is a **sample** reverse-proxy configuration for a separate Caddy container that also joins `shared_network`.

## Kontrola wiring (what’s included right now)

### WordPress MU plugin

`kontrola/wp-content/mu-plugins/kontrola-core.php` mounts into the container and exposes early REST endpoints:

- `GET /wp-json/kontrola/v1/health`
- `GET /wp-json/kontrola/v1/tasks` (requires an authenticated admin)
- `POST /wp-json/kontrola/v1/tasks` (requires an authenticated admin)
- `POST /wp-json/kontrola/v1/ai/generate` (requires editor+)
- `GET /wp-json/kontrola/v1/trends/status` (requires editor+; proxies to the agent)
- `GET /wp-json/kontrola/v1/trends/available-dates` (requires editor+; proxies to the agent)
- `GET /wp-json/kontrola/v1/trends/latest?kind=news|rss&date=YYYY-MM-DD&limit=N` (requires editor+; proxies to the agent)
- `POST /wp-json/kontrola/v1/mobile/auth` (requires `X-Mobile-API-Key`)
- `GET /wp-json/kontrola/v1/mobile/sync/posts` (requires `X-Mobile-API-Key` + `Authorization: Bearer …`)
- `POST /wp-json/kontrola/v1/social/post` (requires `X-Mobile-API-Key` + `Authorization: Bearer …`)

It also creates a small task table on first run using WordPress-native `dbDelta()`.

#### How to test (curl): mobile auth + social enqueue

These examples assume WordPress is running at <http://localhost:8888>.

1. Set a **Mobile API key** (either):

- in WP Admin → Tools → Kontrola → “Mobile API key”, **or**
- via environment variable `MOBILE_API_KEY` (recommended for containerized setups).

1. Authenticate (returns a bearer token):

```bash
BASE_URL=http://localhost:8888
MOBILE_API_KEY='change-me'

curl -sS -X POST "$BASE_URL/wp-json/kontrola/v1/mobile/auth" \
  -H "Content-Type: application/json" \
  -H "X-Mobile-API-Key: $MOBILE_API_KEY" \
  -d '{"username":"admin","password":"YOUR_PASSWORD"}'
```

Copy the returned `token` value (format: `userId.randomHex`).

1. Enqueue a social post (posts immediately if `scheduled_for` is omitted):

```bash
TOKEN='PASTE_TOKEN_HERE'

curl -sS -X POST "$BASE_URL/wp-json/kontrola/v1/social/post" \
  -H "Content-Type: application/json" \
  -H "X-Mobile-API-Key: $MOBILE_API_KEY" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"platform":"twitter","content":{"text":"Hello from Kontrola (queued via REST)!"}}'
```

1. (Optional) Verify the queue as an admin (requires WP login cookies or an authenticated client):

```bash
curl -sS "$BASE_URL/wp-json/kontrola/v1/social/queue?limit=20"
```

Windows note: PowerShell has an alias for `curl`. If the above behaves oddly, use `curl.exe` explicitly.

### Optional external agent service

`services/kontrola-agent` is an optional FastAPI service that can perform text generation.

- It is **profile-gated** in Compose (`profiles: [kontrola]`).
- The WordPress MU plugin calls it via `KONTROLA_AGENT_URL` (default `http://kontrola-agent:8000`).

Enable it by adding `kontrola` to `COMPOSE_PROFILES` in `.env` (e.g. `COMPOSE_PROFILES=dev,kontrola`) and then restarting:

```bash
docker compose up -d --build
```

If `OPENAI_API_KEY` is not set, the agent returns a deterministic stub response so wiring can be tested without external dependencies.

#### Agent dev: run unit tests locally (no Docker required)

If you want to validate the TrendRadar SQLite parsing logic without bringing up containers:

> Tip: the `kontrola-agent` container runs **Python 3.12** (see `services/kontrola-agent/Dockerfile`).
> For the smoothest local install (prebuilt wheels), use Python 3.12/3.13. Very new Python versions may require a Rust toolchain to build `pydantic-core`.

```bash
cd services/kontrola-agent
python -m pip install -r requirements.txt -r requirements-dev.txt
python -m pytest -q
```

### TrendRadar integration (optional)

This repo can optionally run **TrendRadar** (crawler + report UI) and expose its output to WordPress via the **Kontrola agent**.

Design choice: WordPress does **not** speak MCP directly here. Instead, the agent reads TrendRadar’s persisted SQLite output and exposes a stable JSON API, and the MU plugin proxies that under `kontrola/v1/trends/*`.

#### Enable TrendRadar services

1. Add `trends` to `COMPOSE_PROFILES` in `.env` (for example):

```bash
COMPOSE_PROFILES=dev,kontrola,trends
```

1. Start/recreate containers:

```bash
docker compose up -d --build
```

TrendRadar persists its output under `./data/trendradar/output/`.

#### What you get

- TrendRadar report UI (localhost-only): <http://127.0.0.1:${TRENDRADAR_WEBSERVER_PORT:-8080}>
- TrendRadar MCP endpoint (localhost-only): <http://127.0.0.1:${TRENDRADAR_MCP_PORT:-3333}/mcp>
- Kontrola agent JSON endpoints (exposed on port `8787` by default):
  - `GET /trends/status`
  - `GET /trends/available-dates`
  - `GET /trends/latest?kind=news|rss&date=YYYY-MM-DD&limit=N`

The WordPress MU plugin proxies these agent endpoints as:

- `GET /wp-json/kontrola/v1/trends/status`
- `GET /wp-json/kontrola/v1/trends/available-dates`
- `GET /wp-json/kontrola/v1/trends/latest?...`

#### Quick sanity-check (agent)

If you set `KONTROLA_AGENT_SHARED_SECRET`, include it as `X-Kontrola-Secret` when calling the agent:

```bash
curl -sS http://localhost:8787/trends/status \
  -H "X-Kontrola-Secret: $KONTROLA_AGENT_SHARED_SECRET"
```

If TrendRadar hasn’t produced any DBs yet, wait a couple minutes (or adjust the cron schedule via `TRENDRADAR_CRON_SCHEDULE` in `.env`).

## Blueprint alignment (what’s planned)

`Kontrola-blueprint.md` is the long-form design document for the larger Kontrola platform. It includes ideas and sample code for a much bigger “full stack” than what’s currently in this repository.

Notable blueprint concepts (mostly **not implemented here yet**):

- A **Model Context Protocol (MCP)** layer that exposes WordPress capabilities as AI tools (including dynamic discovery of hooks/capabilities).
- A custom **admin/dashboard UX** that can “hide” classic WordPress admin behind branded routes.
- **Mobile** sync/auth endpoints and optional **WebSocket** real-time updates.
- **Social media** automation (queue + scheduling) across multiple platforms.
- **WooCommerce + Printful** integration via native WooCommerce hooks.

As this repo evolves, we’ll pull in these ideas incrementally while keeping the default stack minimal and compatible with the external `shared_network`.

## Vector Database & AI Architecture

Kontrola now includes comprehensive vector database support for RAG (Retrieval-Augmented Generation) operations, enabling:

- **Plugin/Theme Awareness**: Semantic search across installed plugins and themes
- **Content Recommendations**: Find similar posts/pages
- **Admin Command Assistance**: Natural language WP-CLI help
- **Custom Field Search**: Query across ACF/metadata

### Available Backends

Configure via `VECTOR_DB_BACKEND` in `.env`:

| Backend     | Setup                   | Best For                        | Notes                     |
|-------------|-------------------------|---------------------------------|---------------------------|
| `lancedb`   | **Default (embedded)**  | Quick start, prototypes         | No container, GPU support |
| `milvus`    | `--profile milvus`      | Production, billions of vectors | GPU-accelerated, scalable |
| `chroma`    | `--profile chroma`      | Simple RAG, built-in embeddings | Good for small datasets   |
| `qdrant`    | `--profile qdrant`      | Balanced features               | Web UI, excellent filters |
| `pgvector`  | `--profile pgvector`    | SQL-based vectors               | PostgreSQL extension      |
| `pinecone`  | API key only            | Managed cloud service           | No self-hosting needed    |

### Quick Start with Vector DB

```bash
# Start with LanceDB (embedded, no profile needed)
docker compose --profile kontrola up -d

# Or use Milvus for production (requires GPU)
docker compose --profile kontrola --profile milvus up -d
```

### Redis Caching

Enable Redis for performance optimization:

```bash
docker compose --profile kontrola --profile cache up -d
```

Redis caches:
- WordPress query results
- Vector search results
- OpenAI API responses
- Session data

### MinIO Object Storage

For large files (models, backups, media):

```bash
docker compose --profile kontrola --profile minio up -d
```

Access MinIO console at <http://localhost:9001>

### Agent API Endpoints

Once `kontrola-agent` is running (port 8787), test services:

```bash
# Vector store health check
curl -H "X-Kontrola-Secret: your-secret" http://localhost:8787/vector/health

# Redis cache status
curl -H "X-Kontrola-Secret: your-secret" http://localhost:8787/cache/status

# TrendRadar integration (if trends profile enabled)
curl -H "X-Kontrola-Secret: your-secret" http://localhost:8787/trends/status
```

See `VECTOR-DB-ARCHITECTURE.md` for:
- Detailed implementation guide
- RAG use cases and examples
- Performance tuning
- Onboarding flow design
- Security considerations

## WordPress Integration with Vector AI

Kontrola now includes **complete WordPress integration** for vector databases, caching, and RAG (Retrieval-Augmented Generation).

### What's Included

Three new WordPress MU plugins (must-use, auto-loading):

1. **Vector Proxy** (`kontrola-vector-proxy.php`)
   - REST endpoints for vector operations (`/vector/insert`, `/vector/search`, `/vector/health`)
   - Cache management endpoints (`/cache/get`, `/cache/set`, `/cache/delete`)
   - Secure communication with Kontrola Agent

2. **Onboarding Wizard** (`kontrola-onboarding.php` + assets)
   - Interactive 5-step setup wizard in WordPress admin
   - Vector database backend selection (LanceDB, Milvus, Chroma, Qdrant, PGVector, Pinecone)
   - Optional caching (Redis) and object storage (MinIO) configuration
   - Professional UI with progress tracking and health checks

3. **RAG Pipeline** (`kontrola-rag-pipeline.php`)
   - Automatic indexing of plugins, themes, and posts
   - Scheduled via WP-Cron (daily for plugins/themes, every 6 hours for posts)
   - Semantic search across all indexed content
   - Database table for metadata tracking (`wp_kontrola_rag_index`)

### Access the Setup Wizard

After starting the stack, the wizard appears automatically:

```
WordPress Admin → Kontrola → Setup Wizard
```

Or navigate directly to: `http://localhost:8888/wp-admin/admin.php?page=kontrola`

### REST Endpoints

All endpoints are accessible with WordPress nonces and `manage_options` capability:

**Vector Operations**:
```bash
POST /wp-json/kontrola/v1/vector/insert      # Add vectors
POST /wp-json/kontrola/v1/vector/search      # Semantic search
GET /wp-json/kontrola/v1/vector/health       # Health check
```

**Cache Operations**:
```bash
GET /wp-json/kontrola/v1/cache/status        # Cache stats
GET /wp-json/kontrola/v1/cache/get/{key}     # Retrieve value
POST /wp-json/kontrola/v1/cache/set          # Set cache
POST /wp-json/kontrola/v1/cache/delete       # Clear cache
```

**RAG Search**:
```bash
GET /wp-json/kontrola/v1/rag/search?q=query&type=plugin&limit=10
GET /wp-json/kontrola/v1/rag/status          # Indexing status
POST /wp-json/kontrola/v1/rag/reindex        # Manual re-index
```

### Configuration

WordPress stores configuration in the database (`wp_options` table):

```php
get_option('kontrola_vector_backend')      // Selected backend
get_option('kontrola_cache_enabled')       // Cache enabled
get_option('kontrola_object_storage')      // MinIO enabled
get_option('kontrola_agent_url')           // Agent URL
get_option('kontrola_agent_shared_secret') // Auth secret
```

Environment variables override WordPress options:

```bash
KONTROLA_AGENT_URL=http://kontrola-agent:8787
KONTROLA_AGENT_SHARED_SECRET=your-secret
VECTOR_DB_BACKEND=lancedb
```

### Documentation

For detailed information, see:

- **QUICK-REFERENCE.md** - Quick API reference and troubleshooting
- **WORDPRESS-INTEGRATION-SUMMARY.md** - Complete feature documentation
- **WORDPRESS-IMPLEMENTATION-GUIDE.md** - Implementation walkthrough and examples
- **COMPLETION-CHECKLIST.md** - Deployment checklist
- **VECTOR-DB-ARCHITECTURE.md** - Architecture and design details

## Security note

- Treat `.env` as a secret file; **do not commit it**. This repo includes `.env.example` as the template.
- If you ever committed real credentials previously, rotate them.

## License

MIT — see `LICENSE`.

