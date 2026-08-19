# Vector Database & Recommendation Pipeline

The recommendation engine uses vector embeddings of book metadata (+ cover art captions) to calculate book similarity for personalized shelves ("Similar to Recent Books", "New For You", etc.).

## Vector Store Selection: File vs. Qdrant

| Driver | Store Type | Best For | Queue Concurrency | Infrastructure |
| --- | --- | --- | --- | --- |
| **`file`** | File-based (`storage/neuron/neuron.store`) | Small/Medium collections, single-node deployments, zero external dependencies | **Single process only** (`embeddings` queue worker must run with `maxProcesses = 1`) | None (built-in) |
| **`qdrant`** | Distributed Vector Database | Large collections, production scale, high concurrency, parallel queue workers | **Multi-process / Auto-scaling** | Docker container or Qdrant cluster |

> 💡 **Recommendation**: Start with the `file` driver for quick setup and zero infra overhead. Upgrade to **`qdrant`** if you run large collections, require high-speed similarity search, or want multi-worker parallel queue backfills.

## Installing & Setting Up Qdrant

### 1. Run Qdrant with Docker

Run Qdrant locally using Docker:

```bash
docker run -d \
  --name qdrant \
  -p 6333:6333 \
  -p 6334:6334 \
  -v qdrant_storage:/qdrant/storage \
  qdrant/qdrant:latest
```

Or add Qdrant to your `docker-compose.yml`:

```yaml
services:
  qdrant:
    image: qdrant/qdrant:latest
    ports:
      - "6333:6333"
    volumes:
      - qdrant_storage:/qdrant/storage

volumes:
  qdrant_storage:
```

### 2. Configure Environment (`.env`)

To use Qdrant for book recommendation vector storage:

```env
# Enable vector store provider
NEURON_STORE_PROVIDER=qdrant
QDRANT_COLLECTION_URL=http://localhost:6333/collections/audiobook-librarian/
# QDRANT_KEY=your-api-key # Optional if API key authentication is enabled on Qdrant

# Set an embedding provider
NEURON_EMBEDDING_PROVIDER=gemini # or openai, ollama, voyage, mistral
GEMINI_KEY=your-gemini-key
```

### 3. Populate Vector Data & Refresh Recommendations

```bash
# Queue embedding generation for all books missing embeddings
php artisan books:backfill-embeddings

# Queue recommendation shelf recalculation for all users
php artisan books:refresh-recommendations
```
