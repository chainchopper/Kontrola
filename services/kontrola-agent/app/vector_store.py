"""
Vector store abstraction layer for Kontrola Agent.

Provides a unified interface to multiple vector database backends:
- LanceDB (default): embedded, GPU-accelerated, zero-config
- Milvus: production-grade, scalable to billions of vectors
- Chroma: simple, built-in embeddings
- Qdrant: excellent filtering, web UI
- PGVector: PostgreSQL extension for SQL-based vector search
- Pinecone: managed cloud service (no self-hosting)

Backend selection is controlled by the VECTOR_DB_BACKEND environment variable.
"""

from __future__ import annotations

import os
from abc import ABC, abstractmethod
from typing import Any

import numpy as np


class VectorStore(ABC):
    """Abstract base class for vector store implementations."""

    @abstractmethod
    def create_collection(self, name: str, dimension: int, **kwargs) -> None:
        """Create a new collection/table for vectors."""
        pass

    @abstractmethod
    def insert(self, collection: str, vectors: list[list[float]], metadata: list[dict[str, Any]], ids: list[str] | None = None) -> None:
        """Insert vectors with metadata into a collection."""
        pass

    @abstractmethod
    def search(self, collection: str, query_vector: list[float], top_k: int = 10, filter_dict: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        """
        Search for similar vectors.
        Returns list of dicts with keys: id, score, metadata
        """
        pass

    @abstractmethod
    def delete(self, collection: str, ids: list[str]) -> None:
        """Delete vectors by IDs."""
        pass

    @abstractmethod
    def health_check(self) -> dict[str, Any]:
        """Check connection health."""
        pass


class LanceDBStore(VectorStore):
    """LanceDB: Embedded vector database (default, zero-config)."""

    def __init__(self):
        import lancedb

        db_path = os.getenv("LANCEDB_PATH", "/app/data/lancedb")
        self.db = lancedb.connect(db_path)

    def create_collection(self, name: str, dimension: int, **kwargs) -> None:
        # LanceDB creates tables lazily on first insert
        pass

    def insert(self, collection: str, vectors: list[list[float]], metadata: list[dict[str, Any]], ids: list[str] | None = None) -> None:
        table = self.db.open_table(collection) if collection in self.db.table_names() else None
        data = []
        for i, (vec, meta) in enumerate(zip(vectors, metadata)):
            row = {"vector": vec, "id": ids[i] if ids else str(i), **meta}
            data.append(row)
        
        if table:
            table.add(data)
        else:
            self.db.create_table(collection, data=data)

    def search(self, collection: str, query_vector: list[float], top_k: int = 10, filter_dict: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        table = self.db.open_table(collection)
        results = table.search(query_vector).limit(top_k).to_list()
        return [{"id": r["id"], "score": r.get("_distance", 0.0), "metadata": {k: v for k, v in r.items() if k not in ("id", "vector", "_distance")}} for r in results]

    def delete(self, collection: str, ids: list[str]) -> None:
        table = self.db.open_table(collection)
        table.delete(f"id IN {ids}")

    def health_check(self) -> dict[str, Any]:
        return {"ok": True, "backend": "lancedb", "tables": self.db.table_names()}


class MilvusStore(VectorStore):
    """Milvus: Production vector database with GPU support."""

    def __init__(self):
        from pymilvus import connections, Collection

        host = os.getenv("MILVUS_HOST", "milvus")
        port = int(os.getenv("MILVUS_PORT", "19530"))
        connections.connect(host=host, port=port)
        self.Collection = Collection

    def create_collection(self, name: str, dimension: int, **kwargs) -> None:
        from pymilvus import CollectionSchema, FieldSchema, DataType

        fields = [
            FieldSchema(name="id", dtype=DataType.VARCHAR, is_primary=True, max_length=256),
            FieldSchema(name="vector", dtype=DataType.FLOAT_VECTOR, dim=dimension),
            FieldSchema(name="metadata", dtype=DataType.JSON),
        ]
        schema = CollectionSchema(fields, description="Kontrola collection")
        self.Collection(name=name, schema=schema)

    def insert(self, collection: str, vectors: list[list[float]], metadata: list[dict[str, Any]], ids: list[str] | None = None) -> None:
        col = self.Collection(collection)
        if not ids:
            ids = [str(i) for i in range(len(vectors))]
        col.insert([ids, vectors, metadata])

    def search(self, collection: str, query_vector: list[float], top_k: int = 10, filter_dict: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        col = self.Collection(collection)
        col.load()
        results = col.search([query_vector], "vector", {"metric_type": "L2"}, limit=top_k, output_fields=["metadata"])
        return [{"id": hit.id, "score": hit.distance, "metadata": hit.entity.get("metadata")} for hit in results[0]]

    def delete(self, collection: str, ids: list[str]) -> None:
        col = self.Collection(collection)
        col.delete(f"id in {ids}")

    def health_check(self) -> dict[str, Any]:
        from pymilvus import utility

        return {"ok": True, "backend": "milvus", "collections": utility.list_collections()}


class ChromaStore(VectorStore):
    """Chroma: Simple vector DB with built-in embeddings."""

    def __init__(self):
        import chromadb

        host = os.getenv("CHROMA_HOST", "chroma")
        port = int(os.getenv("CHROMA_PORT", "8000"))
        self.client = chromadb.HttpClient(host=host, port=port)

    def create_collection(self, name: str, dimension: int, **kwargs) -> None:
        self.client.get_or_create_collection(name)

    def insert(self, collection: str, vectors: list[list[float]], metadata: list[dict[str, Any]], ids: list[str] | None = None) -> None:
        col = self.client.get_collection(collection)
        if not ids:
            ids = [str(i) for i in range(len(vectors))]
        col.add(embeddings=vectors, metadatas=metadata, ids=ids)

    def search(self, collection: str, query_vector: list[float], top_k: int = 10, filter_dict: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        col = self.client.get_collection(collection)
        results = col.query(query_embeddings=[query_vector], n_results=top_k, where=filter_dict)
        return [{"id": results["ids"][0][i], "score": results["distances"][0][i], "metadata": results["metadatas"][0][i]} for i in range(len(results["ids"][0]))]

    def delete(self, collection: str, ids: list[str]) -> None:
        col = self.client.get_collection(collection)
        col.delete(ids=ids)

    def health_check(self) -> dict[str, Any]:
        return {"ok": True, "backend": "chroma", "collections": [c.name for c in self.client.list_collections()]}


class QdrantStore(VectorStore):
    """Qdrant: Production vector search with excellent filtering."""

    def __init__(self):
        from qdrant_client import QdrantClient

        host = os.getenv("QDRANT_HOST", "qdrant")
        port = int(os.getenv("QDRANT_PORT", "6333"))
        self.client = QdrantClient(host=host, port=port)

    def create_collection(self, name: str, dimension: int, **kwargs) -> None:
        from qdrant_client.models import Distance, VectorParams

        self.client.create_collection(collection_name=name, vectors_config=VectorParams(size=dimension, distance=Distance.COSINE))

    def insert(self, collection: str, vectors: list[list[float]], metadata: list[dict[str, Any]], ids: list[str] | None = None) -> None:
        from qdrant_client.models import PointStruct

        if not ids:
            ids = [str(i) for i in range(len(vectors))]
        points = [PointStruct(id=id_, vector=vec, payload=meta) for id_, vec, meta in zip(ids, vectors, metadata)]
        self.client.upsert(collection_name=collection, points=points)

    def search(self, collection: str, query_vector: list[float], top_k: int = 10, filter_dict: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        from qdrant_client.models import Filter, FieldCondition, MatchValue

        filter_obj = None
        if filter_dict:
            conditions = [FieldCondition(key=k, match=MatchValue(value=v)) for k, v in filter_dict.items()]
            filter_obj = Filter(must=conditions)
        
        results = self.client.search(collection_name=collection, query_vector=query_vector, limit=top_k, query_filter=filter_obj)
        return [{"id": hit.id, "score": hit.score, "metadata": hit.payload} for hit in results]

    def delete(self, collection: str, ids: list[str]) -> None:
        self.client.delete(collection_name=collection, points_selector=ids)

    def health_check(self) -> dict[str, Any]:
        collections = self.client.get_collections().collections
        return {"ok": True, "backend": "qdrant", "collections": [c.name for c in collections]}


class PGVectorStore(VectorStore):
    """PGVector: PostgreSQL extension for SQL-based vector search."""

    def __init__(self):
        import psycopg

        host = os.getenv("PGVECTOR_HOST", "pgvector")
        port = int(os.getenv("PGVECTOR_PORT", "5432"))
        user = os.getenv("PGVECTOR_USER", "kontrola")
        password = os.getenv("PGVECTOR_PASSWORD", "kontrola")
        dbname = os.getenv("PGVECTOR_DB", "vectors")

        self.conn_str = f"host={host} port={port} user={user} password={password} dbname={dbname}"
        # Enable pgvector extension on first connect
        with psycopg.connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                cur.execute("CREATE EXTENSION IF NOT EXISTS vector")
                conn.commit()

    def create_collection(self, name: str, dimension: int, **kwargs) -> None:
        import psycopg

        with psycopg.connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                cur.execute(f"CREATE TABLE IF NOT EXISTS {name} (id TEXT PRIMARY KEY, vector vector({dimension}), metadata JSONB)")
                conn.commit()

    def insert(self, collection: str, vectors: list[list[float]], metadata: list[dict[str, Any]], ids: list[str] | None = None) -> None:
        import psycopg
        import json

        if not ids:
            ids = [str(i) for i in range(len(vectors))]
        
        with psycopg.connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                for id_, vec, meta in zip(ids, vectors, metadata):
                    vec_str = "[" + ",".join(map(str, vec)) + "]"
                    cur.execute(f"INSERT INTO {collection} (id, vector, metadata) VALUES (%s, %s, %s) ON CONFLICT (id) DO UPDATE SET vector = EXCLUDED.vector, metadata = EXCLUDED.metadata", (id_, vec_str, json.dumps(meta)))
                conn.commit()

    def search(self, collection: str, query_vector: list[float], top_k: int = 10, filter_dict: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        import psycopg

        vec_str = "[" + ",".join(map(str, query_vector)) + "]"
        with psycopg.connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                cur.execute(f"SELECT id, vector <-> %s AS distance, metadata FROM {collection} ORDER BY distance LIMIT %s", (vec_str, top_k))
                rows = cur.fetchall()
                return [{"id": row[0], "score": row[1], "metadata": row[2]} for row in rows]

    def delete(self, collection: str, ids: list[str]) -> None:
        import psycopg

        with psycopg.connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                cur.execute(f"DELETE FROM {collection} WHERE id = ANY(%s)", (ids,))
                conn.commit()

    def health_check(self) -> dict[str, Any]:
        import psycopg

        with psycopg.connect(self.conn_str) as conn:
            with conn.cursor() as cur:
                cur.execute("SELECT tablename FROM pg_tables WHERE schemaname = 'public'")
                tables = [row[0] for row in cur.fetchall()]
                return {"ok": True, "backend": "pgvector", "tables": tables}


class PineconeStore(VectorStore):
    """Pinecone: Managed cloud vector database (API-only, no self-hosting)."""

    def __init__(self):
        from pinecone import Pinecone

        api_key = os.getenv("PINECONE_API_KEY", "")
        if not api_key:
            raise ValueError("PINECONE_API_KEY environment variable is required")
        
        self.pc = Pinecone(api_key=api_key)

    def create_collection(self, name: str, dimension: int, **kwargs) -> None:
        from pinecone import ServerlessSpec

        environment = os.getenv("PINECONE_ENVIRONMENT", "us-east-1")
        self.pc.create_index(name=name, dimension=dimension, metric="cosine", spec=ServerlessSpec(cloud="aws", region=environment))

    def insert(self, collection: str, vectors: list[list[float]], metadata: list[dict[str, Any]], ids: list[str] | None = None) -> None:
        index = self.pc.Index(collection)
        if not ids:
            ids = [str(i) for i in range(len(vectors))]
        index.upsert(vectors=[(id_, vec, meta) for id_, vec, meta in zip(ids, vectors, metadata)])

    def search(self, collection: str, query_vector: list[float], top_k: int = 10, filter_dict: dict[str, Any] | None = None) -> list[dict[str, Any]]:
        index = self.pc.Index(collection)
        results = index.query(vector=query_vector, top_k=top_k, filter=filter_dict, include_metadata=True)
        return [{"id": match["id"], "score": match["score"], "metadata": match.get("metadata", {})} for match in results["matches"]]

    def delete(self, collection: str, ids: list[str]) -> None:
        index = self.pc.Index(collection)
        index.delete(ids=ids)

    def health_check(self) -> dict[str, Any]:
        indexes = self.pc.list_indexes()
        return {"ok": True, "backend": "pinecone", "indexes": [idx["name"] for idx in indexes]}


class VectorStoreFactory:
    """Factory for creating vector store instances based on configuration."""

    @staticmethod
    def create() -> VectorStore:
        backend = os.getenv("VECTOR_DB_BACKEND", "lancedb").lower()

        if backend == "lancedb":
            return LanceDBStore()
        elif backend == "milvus":
            return MilvusStore()
        elif backend == "chroma":
            return ChromaStore()
        elif backend == "qdrant":
            return QdrantStore()
        elif backend == "pgvector":
            return PGVectorStore()
        elif backend == "pinecone":
            return PineconeStore()
        else:
            raise ValueError(f"Unknown vector database backend: {backend}. Supported: lancedb, milvus, chroma, qdrant, pgvector, pinecone")


# Convenience function for quick access
def get_vector_store() -> VectorStore:
    """Get the configured vector store instance."""
    return VectorStoreFactory.create()
