FROM python:3.12-slim

WORKDIR /app

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    UV_SYSTEM_PYTHON=1

# Install UV for faster dependency management (prefer over pip)
# UV is a Rust-based Python package installer (~10-100x faster than pip)
RUN pip install --no-cache-dir uv

# Copy dependency files
COPY requirements.txt ./

# Install dependencies using UV (falls back to pip if UV fails)
RUN uv pip install --no-cache-dir -r requirements.txt || pip install --no-cache-dir -r requirements.txt

# Copy application code
COPY app ./app

# Create persistent data directories for LanceDB
RUN mkdir -p /app/data/lancedb

EXPOSE 8000

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
