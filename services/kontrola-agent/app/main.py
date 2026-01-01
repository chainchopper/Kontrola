from __future__ import annotations

import os
from typing import Any

import httpx
from fastapi import FastAPI, Header, HTTPException
from pydantic import BaseModel

try:
    import mysql.connector
except ImportError:
    mysql = None

try:
    import redis
    redis_client = redis.Redis(
        host=os.getenv("REDIS_HOST", "redis"),
        port=int(os.getenv("REDIS_PORT", "6379")),
        db=int(os.getenv("REDIS_DB", "0")),
        decode_responses=True,
        socket_connect_timeout=3,
    )
except ImportError:
    redis_client = None

try:
    from app.vector_store import get_vector_store
    vector_store = get_vector_store()
except Exception:
    vector_store = None

app = FastAPI(title="Kontrola Agent", version="0.2.0")


class GenerateRequest(BaseModel):
    prompt: str
    site: str | None = None
    user: int | None = None


class GenerateResponse(BaseModel):
    text: str
    provider: str


def _require_shared_secret(x_kontrola_secret: str | None) -> None:
    expected = os.getenv("KONTROLA_AGENT_SHARED_SECRET", "")
    if expected:
        if not x_kontrola_secret or x_kontrola_secret != expected:
            raise HTTPException(status_code=401, detail="Invalid X-Kontrola-Secret")


def _get_trendradar_mysql_conn():
    """Connect to the TrendRadar MySQL database."""
    if not mysql:
        return None

    try:
        return mysql.connector.connect(
            host=os.getenv("TRENDRADAR_MYSQL_HOST", "wp-db"),
            port=int(os.getenv("TRENDRADAR_MYSQL_PORT", "3306")),
            user=os.getenv("TRENDRADAR_MYSQL_USER", "wordpressdb"),
            password=os.getenv("TRENDRADAR_MYSQL_PASSWORD", ""),
            database=os.getenv("TRENDRADAR_MYSQL_DATABASE", "trendradar"),
            connection_timeout=5,
        )
    except Exception as e:
        return None


@app.get("/health")
def health() -> dict[str, Any]:
    return {"ok": True, "service": "kontrola-agent", "version": "0.1.0"}


@app.get("/trends/status")
def trends_status(
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Report TrendRadar MySQL integration status."""
    _require_shared_secret(x_kontrola_secret)

    conn = _get_trendradar_mysql_conn()
    if not conn:
        return {
            "ok": False,
            "error": "TrendRadar MySQL backend not configured or unavailable",
            "mysql_available": mysql is not None,
        }

    try:
        cursor = conn.cursor()
        cursor.execute("SELECT COUNT(*) FROM news_items LIMIT 1")
        cursor.fetchone()
        cursor.close()
        conn.close()
        return {
            "ok": True,
            "backend": "mysql",
            "database": os.getenv("TRENDRADAR_MYSQL_DATABASE", "trendradar"),
            "host": os.getenv("TRENDRADAR_MYSQL_HOST", "wp-db"),
        }
    except Exception as e:
        return {
            "ok": False,
            "error": f"Failed to query TrendRadar database: {str(e)}",
        }


@app.get("/trends/available-dates")
def trends_available_dates(
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """List available news/RSS data dates from TrendRadar MySQL."""
    _require_shared_secret(x_kontrola_secret)

    conn = _get_trendradar_mysql_conn()
    if not conn:
        raise HTTPException(
            status_code=503, detail="TrendRadar MySQL backend unavailable"
        )

    try:
        cursor = conn.cursor(dictionary=True)
        # Try to get distinct dates from the news_items table (adjust column names if needed).
        cursor.execute("SELECT DISTINCT DATE(created_at) as date FROM news_items ORDER BY date DESC LIMIT 30")
        news_dates = [row["date"].isoformat() if row["date"] else "" for row in cursor.fetchall()]

        cursor.execute("SELECT DISTINCT DATE(created_at) as date FROM rss_items ORDER BY date DESC LIMIT 30")
        rss_dates = [row["date"].isoformat() if row["date"] else "" for row in cursor.fetchall()]

        cursor.close()
        conn.close()

        return {
            "news": news_dates,
            "rss": rss_dates,
        }
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Database query failed: {str(e)}")


@app.get("/trends/latest")
def trends_latest(
    kind: str = "news",
    date: str | None = None,
    limit: int = 50,
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Return latest news/RSS items from TrendRadar MySQL."""
    _require_shared_secret(x_kontrola_secret)

    kind = kind.strip().lower()
    if kind not in {"news", "rss"}:
        raise HTTPException(status_code=400, detail="kind must be 'news' or 'rss'")

    if limit <= 0:
        limit = 50
    if limit > 200:
        limit = 200

    conn = _get_trendradar_mysql_conn()
    if not conn:
        raise HTTPException(
            status_code=503, detail="TrendRadar MySQL backend unavailable"
        )

    try:
        table = "news_items" if kind == "news" else "rss_items"
        cursor = conn.cursor(dictionary=True)

        if date:
            # Query for a specific date
            sql = f"SELECT * FROM {table} WHERE DATE(created_at) = %s ORDER BY rank ASC LIMIT %s"
            cursor.execute(sql, (date, limit))
        else:
            # Query the latest date's items
            sql = f"SELECT * FROM {table} ORDER BY created_at DESC, rank ASC LIMIT %s"
            cursor.execute(sql, (limit,))

        rows = cursor.fetchall()
        cursor.close()
        conn.close()

        if not rows:
            raise HTTPException(
                status_code=404,
                detail=f"No TrendRadar {kind} data found. Ensure the `trends` profile is running and TrendRadar has populated the MySQL database.",
            )

        items = []
        for row in rows:
            items.append(
                {
                    "id": row.get("id"),
                    "title": row.get("title"),
                    "url": row.get("url") or row.get("link"),
                    "rank": row.get("rank") or row.get("position"),
                    "platform_id": row.get("platform_id") or row.get("source_id"),
                    "platform_name": row.get("platform_name"),
                    "created_at": row.get("created_at"),
                }
            )

        return {
            "ok": True,
            "kind": kind,
            "date": date or "latest",
            "count": len(items),
            "items": items,
        }
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Database query failed: {str(e)}")


@app.post("/generate", response_model=GenerateResponse)
async def generate(
    req: GenerateRequest,
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> GenerateResponse:
    _require_shared_secret(x_kontrola_secret)

    # Minimal implementation:
    # - If OPENAI_API_KEY is present, call OpenAI Chat Completions.
    # - Otherwise return a deterministic stub.
    openai_key = os.getenv("OPENAI_API_KEY", "").strip()
    if not openai_key:
        return GenerateResponse(
            text=(
                "[kontrola-agent stub] OPENAI_API_KEY is not configured. "
                "Set OPENAI_API_KEY in .env to enable real generation.\n\n"
                f"Prompt was: {req.prompt}"
            ),
            provider="stub",
        )

    # NOTE: This uses the OpenAI public endpoint. If you later want GitHub Models / Azure,
    # adjust base_url + auth accordingly.
    url = "https://api.openai.com/v1/chat/completions"
    model = os.getenv("OPENAI_MODEL", "gpt-4o-mini")

    payload = {
        "model": model,
        "messages": [
            {
                "role": "system",
                "content": (
                    "You are Kontrola Agent. Return concise marketing-focused output. "
                    "Avoid HTML unless asked."
                ),
            },
            {"role": "user", "content": req.prompt},
        ],
        "temperature": 0.7,
    }

    async with httpx.AsyncClient(timeout=30) as client:
        r = await client.post(
            url,
            headers={
                "Authorization": f"Bearer {openai_key}",
                "Content-Type": "application/json",
            },
            json=payload,
        )

    if r.status_code >= 400:
        raise HTTPException(status_code=502, detail=f"Upstream OpenAI error: {r.text}")

    data = r.json()
    try:
        text = data["choices"][0]["message"]["content"]
    except Exception:
        raise HTTPException(status_code=502, detail="Unexpected OpenAI response format")

    return GenerateResponse(text=text, provider=f"openai:{model}")


# ============================================================================
# VECTOR STORE ENDPOINTS (RAG functionality)
# ============================================================================


class VectorInsertRequest(BaseModel):
    collection: str
    vectors: list[list[float]]
    metadata: list[dict[str, Any]]
    ids: list[str] | None = None


class VectorSearchRequest(BaseModel):
    collection: str
    query_vector: list[float]
    top_k: int = 10
    filter: dict[str, Any] | None = None


@app.post("/vector/insert")
def vector_insert(
    req: VectorInsertRequest,
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Insert vectors with metadata into a collection."""
    _require_shared_secret(x_kontrola_secret)

    if not vector_store:
        raise HTTPException(
            status_code=503,
            detail=f"Vector store backend '{os.getenv('VECTOR_DB_BACKEND', 'lancedb')}' is not configured or unavailable",
        )

    try:
        vector_store.insert(req.collection, req.vectors, req.metadata, req.ids)
        return {"ok": True, "inserted": len(req.vectors)}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Vector insert failed: {str(e)}")


@app.post("/vector/search")
def vector_search(
    req: VectorSearchRequest,
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Search for similar vectors in a collection."""
    _require_shared_secret(x_kontrola_secret)

    if not vector_store:
        raise HTTPException(
            status_code=503,
            detail=f"Vector store backend '{os.getenv('VECTOR_DB_BACKEND', 'lancedb')}' is not configured or unavailable",
        )

    try:
        results = vector_store.search(req.collection, req.query_vector, req.top_k, req.filter)
        return {"ok": True, "results": results}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Vector search failed: {str(e)}")


@app.get("/vector/health")
def vector_health(
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Check vector store health and configuration."""
    _require_shared_secret(x_kontrola_secret)

    if not vector_store:
        return {
            "ok": False,
            "backend": os.getenv("VECTOR_DB_BACKEND", "lancedb"),
            "error": "Vector store not initialized",
        }

    try:
        return vector_store.health_check()
    except Exception as e:
        return {
            "ok": False,
            "backend": os.getenv("VECTOR_DB_BACKEND", "lancedb"),
            "error": str(e),
        }


# ============================================================================
# REDIS CACHING ENDPOINTS
# ============================================================================


@app.get("/cache/status")
def cache_status(
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Check Redis cache connection status."""
    _require_shared_secret(x_kontrola_secret)

    if not redis_client:
        return {
            "ok": False,
            "error": "Redis client not configured",
            "redis_available": False,
        }

    try:
        redis_client.ping()
        info = redis_client.info("stats")
        return {
            "ok": True,
            "backend": "redis",
            "host": os.getenv("REDIS_HOST", "redis"),
            "port": os.getenv("REDIS_PORT", "6379"),
            "db": os.getenv("REDIS_DB", "0"),
            "total_connections_received": info.get("total_connections_received", 0),
            "total_commands_processed": info.get("total_commands_processed", 0),
            "keyspace_hits": info.get("keyspace_hits", 0),
            "keyspace_misses": info.get("keyspace_misses", 0),
        }
    except Exception as e:
        return {
            "ok": False,
            "error": f"Redis connection failed: {str(e)}",
            "redis_available": True,
        }


@app.get("/cache/get/{key}")
def cache_get(
    key: str,
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Get a value from Redis cache."""
    _require_shared_secret(x_kontrola_secret)

    if not redis_client:
        raise HTTPException(status_code=503, detail="Redis not configured")

    try:
        value = redis_client.get(key)
        return {"ok": True, "key": key, "value": value, "found": value is not None}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Cache get failed: {str(e)}")


@app.post("/cache/set/{key}")
def cache_set(
    key: str,
    value: str,
    ttl: int | None = None,
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Set a value in Redis cache with optional TTL (seconds)."""
    _require_shared_secret(x_kontrola_secret)

    if not redis_client:
        raise HTTPException(status_code=503, detail="Redis not configured")

    try:
        redis_client.set(key, value, ex=ttl)
        return {"ok": True, "key": key, "ttl": ttl}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Cache set failed: {str(e)}")


@app.delete("/cache/delete/{key}")
def cache_delete(
    key: str,
    x_kontrola_secret: str | None = Header(default=None, convert_underscores=False),
) -> dict[str, Any]:
    """Delete a key from Redis cache."""
    _require_shared_secret(x_kontrola_secret)

    if not redis_client:
        raise HTTPException(status_code=503, detail="Redis not configured")

    try:
        deleted = redis_client.delete(key)
        return {"ok": True, "key": key, "deleted": deleted > 0}
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Cache delete failed: {str(e)}")

