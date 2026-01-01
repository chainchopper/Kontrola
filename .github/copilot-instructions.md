# Copilot instructions for this repo

This repository is **primarily a Docker Compose stack for self-hosting WordPress**, intended to run cleanly alongside other self-hosted services on a **shared Docker network**.

It is also the *foundation repo* for the planned **Kontrola AI marketing platform** (see `Kontrola-blueprint.md`). At the moment, most “Kontrola platform” code is scaffolding/wiring, not a complete product.

## Big picture architecture (what runs)

The stack is defined in `docker-compose.yml`:

- `wp-db` (MySQL): persistent data in `./data/wp-db`
- `wp` (WordPress): web server on `http://localhost:8888`, persistent files in `./data/wp`
- `wp-cli` (WP-CLI): runs WP management commands against the same mounted WordPress volume
- `pma` (phpMyAdmin): database UI on `http://localhost:8081`

**Network model:** all services join an **external** Docker network named `shared_network`.

That means the stack will not create the network for you; it must exist ahead of time.

## Key files and conventions

- `docker-compose.yml` is the source of truth for service names, ports, volumes, and the external network.
- `.env` provides the MySQL credentials used by `wp-db`, `wp`, `wp-cli`, and `pma`.
  - Variable names are `MYSQL_ROOT_PWD`, `MYSQL_DB`, `MYSQL_USER`, `MYSQL_PWD`.
  - Treat `.env` as a secret file. Use `.env.example` as the template.
- `config/php.ini` is mounted into PHP containers to raise upload/memory/time limits.
- `config/Caddyfile` is a **sample** reverse-proxy config (intended for pairing with a separate Caddy container on the same `shared_network`).
- `kontrola/` contains **WordPress-native “Kontrola” wiring** that gets bind-mounted into the running WordPress container.
  - `kontrola/wp-content/mu-plugins/kontrola-core.php` is a must-use plugin that adds `kontrola/v1` REST endpoints and a small DB-backed task queue.
  - `kontrola/wp-content/plugins/` is reserved for future (optional) standard plugins.
- `services/kontrola-agent/` is an **optional** external agent service (FastAPI) used by the MU plugin for `/kontrola/v1/ai/generate`.
- `services/trendradar/` contains safe, committable TrendRadar config templates.

### TrendRadar integration (implemented)

- TrendRadar runs behind the Compose profile `trends` (see `docker-compose.yml`).
- TrendRadar persists output under `./data/trendradar/output`.
- WordPress does **not** talk to TrendRadar MCP directly in this repo.
  - Instead, `services/kontrola-agent` reads TrendRadar’s SQLite output and exposes JSON endpoints (`/trends/*`).
  - The MU plugin proxies these under `kontrola/v1/trends/*`.
- Helper scripts:
  - `restart-docker.sh`: stop/start the compose stack.
  - `update-docker.sh`: pull images, recreate containers.
  - `backup.sh`: runs a compressed MySQL dump from inside `wp-db` into `./backups/`.

### Docker Compose nuance

This repo uses both spellings:

- Scripts use `docker compose ...` (Compose v2)
- `backup.sh` uses `docker-compose ...` (Compose v1)

When updating scripts or docs, keep this in mind for compatibility. Prefer not to “fix” it casually unless you verify the target environment.

## Common workflows (what to run)

### First-time setup

1. Create the external network (once per Docker host):
   - `docker network create shared_network`
2. Copy `.env.example` to `.env` and set real credentials.
3. Start services:
   - `docker compose up -d`

### Day-to-day

- Start: `docker compose up -d`
- Stop: `docker compose down`
- Restart: `./restart-docker.sh`
- Update images + restart: `./update-docker.sh`
- Backup DB: `./backup.sh`

### WP-CLI usage

Run WP-CLI inside the `wp-cli` service (it shares the same WordPress volume as `wp`). Example pattern:

- `docker compose exec wp-cli wp <command>`

## How “Kontrola” wiring fits in

The design document `Kontrola-blueprint.md` describes an AI-driven marketing platform built *on top of* WordPress.

In this repo we keep “Kontrola” code (when present) as **WordPress-native extensions**:

- Prefer WordPress APIs (REST, hooks, WP-Cron, `$wpdb`, roles/caps) over direct database edits.
- Expose agent operations via custom REST routes under the `kontrola/v1` namespace.

If you modify the MU plugin task queue, prefer extending:

- `Kontrola_Core_Mu::process_tasks()` (background execution)
- `Kontrola_Core_Mu::create_task()` and the REST routes (task intake)

If you add more Kontrola code, follow these repo-specific constraints:

- Do not add or require an additional Docker network: everything must attach to `shared_network`.
- Persist long-lived state under `./data/` (already ignored by `.gitignore`).
- Keep local secrets in `.env` only; never hardcode credentials in tracked files.

## Blueprint alignment (planned architecture — not fully implemented here)

`Kontrola-blueprint.md` is intentionally ambitious. It describes (among other things) a future “full platform” that goes beyond this repository’s current minimal WordPress stack.

When using the blueprint as guidance, keep a strict separation between:

- **Implemented in this repo today**: WordPress + MySQL + phpMyAdmin + WP-CLI, plus the MU plugin + optional FastAPI `kontrola-agent` service.
- **Planned / blueprint concepts**: items below may influence *how we extend the wiring*, but should not be described as present unless added to the repo.

Key blueprint concepts that may shape future work:

- **MCP-first tooling**: a built-in Kontrola MCP server that exposes WordPress capabilities as AI tools.
  - Blueprint leans on WordPress hook discovery (`$wp_filter`) and the emerging WordPress “Abilities API” idea to register/describe tools.
  - External MCP servers (GitHub, local tools, etc.) are configured by admins and connected via STDIO/HTTP.
- **“Opaque WordPress” UX** (planned): custom dashboard and route/path rewriting (e.g., branding `/wp-admin/` to something like `/kontrola/console/`). This repo does **not** patch WordPress core or rewrite admin paths today.
- **Mobile sync endpoints** (planned): REST endpoints for mobile authentication + syncing posts/products/analytics/tasks, plus WebSocket auth.
- **Social automation** (planned): a unified social manager with a DB-backed queue, WP-Cron scheduling, and per-platform integrations.
- **E-commerce/Printful + WooCommerce** (planned): integrations should be built on native WooCommerce hooks and the WP HTTP API.
- **Real-time sync** (planned): a WebSocket service and pub/sub (often Redis) for streaming updates to dashboards and mobile clients.

### If you implement blueprint features here

Keep changes incremental and compatible with this repo’s operational goals:

- Prefer **WordPress-native implementations** first (REST routes, hooks, WP-Cron, `$wpdb`, capabilities) before introducing new services.
- If adding new services (Node/Redis/WebSocket/etc.), keep them **profile-gated** and attached to the existing external `shared_network`.
- Do not add a new Docker network. All services must remain able to run alongside other stacks on the shared network.
- Avoid claiming the presence of a “full platform” in docs until the corresponding folders/services exist in this repository.

## Where to look before changing things

- Compose wiring / ports / volumes: `docker-compose.yml`
- Reverse proxy expectations: `config/Caddyfile`
- PHP limits impacting uploads/timeouts: `config/php.ini`
- Operational scripts: `backup.sh`, `restart-docker.sh`, `update-docker.sh`
- Product direction & planned components: `Kontrola-blueprint.md`
