<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Http\Controllers\Api\Traits\BookTransformTrait;
use App\Models\Book;
use App\Models\BookEmbedding;
use App\Services\AI\AIProviderFactory;
use App\Services\Embeddings\BookEmbeddingTextBuilder;
use App\Services\Embeddings\EmbeddingPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NeuronAI\RAG\Document;

/**
 * Embeds a book's metadata (+ an AI-generated cover-art caption) into the
 * recommendation vector store. See EmbeddingPipeline for provider/store
 * resolution, and BookEmbedding for the staleness-tracking table.
 *
 * Concurrency note: the 'file' vector store driver appends without file
 * locking (see FileVectorStore::addDocuments). This job is dispatched onto
 * the dedicated 'embeddings' queue, which MUST be run with a single worker
 * (`queue:work --queue=embeddings`, no `--concurrency` > 1) to avoid
 * interleaved writes during a bulk backfill or batch of edits.
 */
class EmbedBookJob implements ShouldQueue
{
    use BookTransformTrait;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;

    public function __construct(protected int $bookId, protected bool $force = false)
    {
        $this->onQueue('embeddings');
    }

    public function handle(EmbeddingPipeline $pipeline): void
    {
        $book = Book::with(['authors', 'narrators', 'series', 'genres'])->find($this->bookId);
        if (!$book) {
            return;
        }

        if (!$pipeline->isAvailable()) {
            Log::debug('Skipping book embedding: no embedding provider configured', ['book_id' => $book->id]);
            return;
        }

        $coverHash = $this->coverHash($book);
        $embeddingRecord = BookEmbedding::firstOrNew(['book_id' => $book->id]);

        $coverCaption = !$this->force && $embeddingRecord->exists && $embeddingRecord->cover_hash === $coverHash
            ? $embeddingRecord->cover_caption
            : $this->generateCoverCaption($book);

        $embedText = trim((new BookEmbeddingTextBuilder())->buildMetadataText($book) . "\n\n" . ($coverCaption ?? ''));
        $contentHash = sha1($embedText);

        if (!$this->force && $embeddingRecord->exists && $embeddingRecord->content_hash === $contentHash) {
            return;
        }

        $provider = $pipeline->resolveEmbeddingProvider();
        $vector = $provider->embedText($embedText);

        $document = new Document($embedText);
        $document->id = (string) $book->id;
        $document->sourceType = 'book';
        $document->sourceName = (string) $book->id;
        $document->embedding = $vector;
        $document->addMetadata('book_id', $book->id);

        $store = $pipeline->resolveVectorStore();

        if ($embeddingRecord->exists) {
            try {
                $store->deleteBy('book', (string) $book->id);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete prior book embedding before re-adding', [
                    'book_id' => $book->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $store->addDocument($document);

        $embeddingRecord->fill([
            'content_hash' => $contentHash,
            'cover_hash' => $coverHash,
            'cover_caption' => $coverCaption,
            'embedded_at' => now(),
        ])->save();
    }

    /**
     * A cover caption is only regenerated when the cover itself changes, since the
     * vision-API call is the most expensive step. Remote (proxied) covers are
     * intentionally skipped rather than fetched here (see UNTESTABLE_REGRESSIONS.md).
     */
    protected function generateCoverCaption(Book $book): ?string
    {
        if (empty($book->cover_image) || $this->isRemoteUrl($book->cover_image)) {
            return null;
        }

        $coverPath = $this->resolveCoverImagePath($book->cover_image, $book->directory_path);
        if (!Storage::disk('books')->exists($coverPath)) {
            return null;
        }

        $provider = AIProviderFactory::make();
        $response = $provider->describeImage(Storage::disk('books')->path($coverPath));

        if (!$response->isSuccess()) {
            Log::warning('Cover caption generation failed', [
                'book_id' => $book->id,
                'error' => $response->getError(),
            ]);
            return null;
        }

        return $response->getContent();
    }

    protected function coverHash(Book $book): ?string
    {
        if (empty($book->cover_image) || $this->isRemoteUrl($book->cover_image)) {
            return null;
        }

        $coverPath = $this->resolveCoverImagePath($book->cover_image, $book->directory_path);
        if (!Storage::disk('books')->exists($coverPath)) {
            return null;
        }

        $fullPath = Storage::disk('books')->path($coverPath);
        return sha1($coverPath . '|' . filemtime($fullPath) . '|' . filesize($fullPath));
    }
}
