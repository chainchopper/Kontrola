# Vector Database & Caching Architecture

## Overview

This document describes Kontrola's flexible storage backend architecture that allows users to select vector databases, caching layers, and object storage during onboarding.

## Design Principles

1. **WordPress-Native First**: Use WordPress APIs (wp_options, REST, hooks) for configuration
2. **Modular Selection**: Users choose which services to enable during onboarding
3. **Graceful Degradation**: Services remain optional; core WordPress functionality works without them
4. **No Duplication**: Reuse existing infrastructure (MySQL for metadata, shared_network for containers)
5. **Performance Tooling**: Use UV > pip, Rust > Python where feasible

## Architecture Stack

### Caching Layer (Redis)
- **Purpose**: Fast key-value caching, SQL query result caching, session storage
- **Profile**: `cache`
- **Port**: 6379 (internal only)
- **Use Cases**:
  - Cache WordPress query results to reduce MySQL load
  - Store temporary agent state (task queue, rate limits)
  - Session management for mobile sync

### Vector Databases (RAG & Semantic Search)

#### LanceDB (Default/Bundled)
- **Status**: Default, no container needed (embedded)
- **Purpose**: Vector search for plugin/theme metadata, content similarity
- **Profile**: Enabled by default (no profile needed)
- **Storage**: `./data/kontrola/lancedb/`
- **Advantages**: 
  - Zero-config embedded database
  - Native Python integration
  - Fast GPU-accelerated queries
  - No external dependencies
  - Versioned datasets

#### Milvus (Recommended for Production)
- **Profile**: `milvus`
- **Ports**: 19530 (gRPC), 9091 (metrics), 19121 (Attu web UI)
- **Purpose**: High-performance vector database with GPU acceleration
- **Use Cases**:
  - Large-scale RAG (>1M vectors)
  - Multi-tenant vector search
  - Advanced filtering + hybrid search
- **Dependencies**: etcd, MinIO (managed in compose)
- **Advantages**:
  - Best performance for large datasets
  - GPU acceleration
  - Scalable to billions of vectors
  - Rich query language

#### Chroma
- **Profile**: `chroma`
- **Port**: 8000 (internal)
- **Purpose**: Simple vector database with built-in embeddings
- **Use Cases**:
  - Quick RAG prototypes
  - Lightweight semantic search
- **Advantages**:
  - Simple API
  - Built-in embedding generation
  - Good for smaller datasets

#### Qdrant
- **Profile**: `qdrant`
- **Ports**: 6333 (HTTP), 6334 (gRPC), 6335 (web UI)
- **Purpose**: Production-ready vector search with web UI
- **Use Cases**:
  - Medium-scale vector search
  - Filtered semantic search
  - Real-time recommendations
- **Advantages**:
  - Excellent filtering capabilities
  - User-friendly web UI
  - Good balance of features/simplicity

#### PGVector (PostgreSQL Extension)
- **Profile**: `pgvector`
- **Port**: 5432
- **Purpose**: Vector search within PostgreSQL
- **Use Cases**:
  - When you already use PostgreSQL
  - SQL-based vector queries
  - Co-locate vectors with relational data
- **Advantages**:
  - Native SQL integration
  - ACID compliance
  - Mature ecosystem

#### Pinecone (Cloud SaaS)
- **Profile**: N/A (API-only)
- **Configuration**: API key via onboarding
- **Purpose**: Managed vector database (no self-hosting)
- **Use Cases**:
  - Zero-ops vector search
  - Multi-region deployments
- **Advantages**:
  - Fully managed (no containers)
  - Auto-scaling
  - Enterprise support

### Object Storage (MinIO)
- **Profile**: `minio`
- **Ports**: 9000 (API), 9001 (console)
- **Purpose**: S3-compatible object storage for large files
- **Use Cases**:
  - Store AI model files
  - Image/video uploads
  - Backup storage
  - Training datasets
- **Advantages**:
  - S3-compatible API
  - Self-hosted
  - Web console UI

## Onboarding Flow

### Step 1: Welcome & Architecture Overview
- Brief explanation of Kontrola's AI capabilities
- Explain why vector databases enhance WordPress

### Step 2: Caching Layer Selection
- **Redis** (recommended): Fast caching, reduces MySQL load
- **None**: Use MySQL only (slower)

### Step 3: Vector Database Selection
- **LanceDB** (default): Zero-config, embedded, fast
- **Milvus** (recommended for production): GPU-accelerated, scalable
- **Chroma**: Simple, good for prototypes
- **Qdrant**: Production-ready with web UI
- **PGVector**: PostgreSQL-based vectors
- **Pinecone**: Managed cloud service (API key required)
- **None**: Disable RAG features

### Step 4: Object Storage Selection
- **MinIO** (recommended): Self-hosted S3-compatible storage
- **None**: Use MySQL blob storage (not recommended)

### Step 5: Connection Testing
- Test connection to each selected service
- Display health checks (green/red indicators)
- Allow retry or service selection change

### Step 6: Configuration Persistence
- Save selections to `wp_options` table:
  - `kontrola_vector_db`: `lancedb|milvus|chroma|qdrant|pgvector|pinecone|none`
  - `kontrola_cache_backend`: `redis|none`
  - `kontrola_object_storage`: `minio|none`
  - `kontrola_*_config`: JSON config for each service (host, port, credentials)

## Service Configuration

### Docker Compose Profiles

Users start services based on their onboarding selections:

```bash
# Minimal WordPress only
docker compose up -d

# WordPress + Redis + LanceDB (embedded)
docker compose --profile cache up -d

# WordPress + Milvus + Redis + MinIO
docker compose --profile cache --profile milvus --profile minio up -d

# Full stack (all services)
docker compose --profile cache --profile milvus --profile chroma --profile qdrant --profile pgvector --profile minio up -d
```

### Default Configurations

All services are pre-configured with secure defaults:
- **Redis**: No auth (internal network only), 1GB memory limit
- **Milvus**: Default etcd + MinIO dependencies, GPU support if available
- **Chroma**: Persistent storage in `./data/kontrola/chroma`
- **Qdrant**: Persistent storage in `./data/kontrola/qdrant`
- **PGVector**: PostgreSQL 16 with pgvector extension
- **MinIO**: Default credentials (user changeable during onboarding)

## RAG Implementation

### Use Cases

1. **Plugin/Theme Awareness**
   - Index all installed plugins' readme.txt + code signatures
   - Semantic search: "find plugins that handle payments"
   - Auto-suggest compatible plugins

2. **Content Recommendations**
   - Index post content
   - Find similar articles
   - Auto-suggest internal links

3. **Admin Command Assistance**
   - Index WP-CLI commands + documentation
   - Natural language queries: "how do I export users?"
   - Context-aware help

4. **Custom Field Search**
   - Index ACF/custom field data
   - Semantic queries across metadata

### Implementation Details

#### Indexing Pipeline (kontrola-agent)
```
WordPress Content → kontrola-agent → Embeddings (OpenAI/local) → Vector DB
```

#### Query Pipeline
```
User Query → kontrola-agent → Vector Search → Ranked Results → WordPress REST
```

#### Caching Strategy (Redis)
- Cache embedding vectors (TTL: 24h)
- Cache frequent search results (TTL: 1h)
- Invalidate on content update (WordPress hook)

## Admin Dashboard Integration

### Service Status Widget
- Real-time health checks for all enabled services
- Connection indicators (green/yellow/red)
- Quick links to external UIs:
  - Milvus Attu: `http://localhost:19121`
  - Qdrant UI: `http://localhost:6335`
  - MinIO Console: `http://localhost:9001`
  - phpMyAdmin: `http://localhost:8081`

### Configuration Panel
- Re-run onboarding wizard
- Test connections
- View service logs
- Restart services (via WP-CLI + docker compose)

### RAG Settings
- Enable/disable RAG features
- Configure embedding model (OpenAI/local)
- Set indexing schedule (WP-Cron)
- View indexed content statistics

## Performance Considerations

### MySQL Offloading
- Cache WordPress queries in Redis (80% hit rate goal)
- Store embeddings in vector DB (not MySQL blobs)
- Use MinIO for large files (not MySQL)

### Embedding Strategy
- Use OpenAI `text-embedding-3-small` (default, fast, cheap)
- Optional local embeddings (sentence-transformers, slower but free)
- Batch processing (100 items/request)
- Queue long operations (WP-Cron background jobs)

### Vector DB Selection Guidance
- **< 100K vectors**: LanceDB (embedded, fast)
- **100K - 10M vectors**: Qdrant or Chroma
- **> 10M vectors**: Milvus with GPU
- **Enterprise**: Pinecone (managed)

## Security

### Network Isolation
- All services on `shared_network` (bridge mode)
- External ports only for UIs (localhost-bound)
- API endpoints require WordPress auth + KONTROLA_AGENT_SHARED_SECRET

### Credential Management
- All credentials in `.env` (never tracked)
- WordPress stores hashed API keys in `wp_options`
- MinIO uses generated access keys (user-configurable)

### Data Privacy
- Vector embeddings stored separately from source content
- Option to use local embedding models (no OpenAI API calls)
- Data retention policies per service

## Migration & Backup

### Backup Strategy
- MySQL: `backup.sh` (existing)
- Redis: Snapshots to `./data/redis/dump.rdb`
- Vector DBs: Export to JSON via REST APIs
- MinIO: S3 sync to external storage

### Service Migration
- Switch vector DB: Re-run onboarding → Re-index content
- Warning shown: "Existing vectors will be lost unless exported"
- Export/import utilities in WP-CLI

## Development Workflow

### Dependency Management
1. **Preferred**: UV (`uv pip install lancedb chromadb`)
2. **Fallback**: pip (`pip install lancedb chromadb`)
3. **Native**: Rust bindings where available (LanceDB uses Rust core)

### Docker Build
- Use UV in Dockerfile for faster installs
- Multi-stage builds to minimize image size
- Cache Python dependencies layer

### Testing
- Mock vector DB connections in unit tests
- Integration tests with real containers (CI)
- Health check endpoints for smoke tests

## Roadmap

### Phase 1: Core Infrastructure ✅ (Current)
- Redis caching layer
- LanceDB embedded (default)
- Onboarding REST endpoints

### Phase 2: Additional Vector DBs
- Milvus GPU support
- Chroma integration
- Qdrant integration
- PGVector extension

### Phase 3: RAG Pipeline
- Plugin/theme indexing
- Semantic search endpoints
- WordPress admin UI

### Phase 4: Advanced Features
- MinIO object storage
- Multi-tenant isolation
- Hybrid search (keyword + vector)
- Auto-scaling (Kubernetes)

## References

- LanceDB: https://docs.lancedb.com/
- Milvus: https://milvus.io/docs/
- Chroma: https://docs.trychroma.com/
- Qdrant: https://qdrant.tech/documentation/
- PGVector: https://github.com/pgvector/pgvector
- Pinecone: https://docs.pinecone.io/
- MinIO: https://min.io/docs/
