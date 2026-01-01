import os
import sqlite3
from pathlib import Path

import pytest
from fastapi import HTTPException

from app import main


def _write_sqlite(db_path: Path, statements: list[str]) -> None:
    db_path.parent.mkdir(parents=True, exist_ok=True)
    with sqlite3.connect(str(db_path)) as conn:
        for st in statements:
            conn.execute(st)
        conn.commit()


def test_iter_db_files_supports_new_and_legacy_layouts(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("TRENDRADAR_OUTPUT_DIR", str(tmp_path))

    # New layout: output/news/YYYY-MM-DD.db
    new_db = tmp_path / "news" / "2025-12-30.db"
    _write_sqlite(new_db, ["CREATE TABLE news_items(id INTEGER PRIMARY KEY, title TEXT, url TEXT, rank INTEGER)"])

    # Legacy layout: output/YYYY-MM-DD/news.db
    legacy_db = tmp_path / "2025-12-29" / "news.db"
    _write_sqlite(legacy_db, ["CREATE TABLE news_items(id INTEGER PRIMARY KEY, title TEXT, url TEXT, rank INTEGER)"])

    items = main._iter_db_files("news")
    assert [d for d, _ in items] == ["2025-12-29", "2025-12-30"]


def test_iter_db_files_dedup_prefers_flattened_layout(tmp_path: Path, monkeypatch: pytest.MonkeyPatch) -> None:
    monkeypatch.setenv("TRENDRADAR_OUTPUT_DIR", str(tmp_path))

    # Same date in both layouts; ensure flattened wins.
    flattened = tmp_path / "news" / "2025-12-30.db"
    legacy = tmp_path / "2025-12-30" / "news.db"

    _write_sqlite(flattened, ["CREATE TABLE news_items(id INTEGER PRIMARY KEY, title TEXT)"])
    _write_sqlite(legacy, ["CREATE TABLE news_items(id INTEGER PRIMARY KEY, title TEXT)"])

    items = dict(main._iter_db_files("news"))
    assert items["2025-12-30"].parent.name == "news"


def test_query_latest_items_news_joins_platforms_and_orders_by_rank(tmp_path: Path) -> None:
    db = tmp_path / "news" / "2025-12-30.db"

    _write_sqlite(
        db,
        [
            "CREATE TABLE platforms(id INTEGER PRIMARY KEY, name TEXT)",
            "CREATE TABLE news_items(id INTEGER PRIMARY KEY, title TEXT, url TEXT, rank INTEGER, platform_id INTEGER)",
            "INSERT INTO platforms(id, name) VALUES (1, 'ExamplePlatform')",
            "INSERT INTO news_items(id, title, url, rank, platform_id) VALUES (10, 'B', 'https://b', 2, 1)",
            "INSERT INTO news_items(id, title, url, rank, platform_id) VALUES (11, 'A', 'https://a', 1, 1)",
        ],
    )

    items = main._query_latest_items(db, kind="news", limit=50)
    assert len(items) == 2

    # Ordered by rank ASC
    assert items[0]["title"] == "A"
    assert items[1]["title"] == "B"

    assert items[0]["platform_name"] == "ExamplePlatform"


def test_query_latest_items_rss_works_without_platforms(tmp_path: Path) -> None:
    db = tmp_path / "rss" / "2025-12-30.db"

    _write_sqlite(
        db,
        [
            "CREATE TABLE rss_items(id INTEGER PRIMARY KEY, title TEXT, url TEXT, rank INTEGER)",
            "INSERT INTO rss_items(id, title, url, rank) VALUES (1, 'X', 'https://x', 1)",
        ],
    )

    items = main._query_latest_items(db, kind="rss", limit=10)
    assert items[0]["title"] == "X"
    assert items[0]["platform_name"] is None


def test_query_latest_items_raises_on_unrecognized_schema(tmp_path: Path) -> None:
    db = tmp_path / "news" / "2025-12-30.db"
    _write_sqlite(db, ["CREATE TABLE something_else(id INTEGER PRIMARY KEY)"])

    with pytest.raises(HTTPException):
        main._query_latest_items(db, kind="news", limit=5)
