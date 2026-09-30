<?php

declare(strict_types=1);

namespace App\Services\Imports;

/**
 * Optional external metadata lookup used while interpreting a draft.
 *
 * Receives and returns metadata in the legacy importer shape (title, author[],
 * narrator[], series, series_number, genre, description, ...). Implementations
 * may throw; the interpreter treats any failure as "no enrichment".
 */
interface ImportMetadataEnricher
{
    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function enrich(array $metadata): array;
}
