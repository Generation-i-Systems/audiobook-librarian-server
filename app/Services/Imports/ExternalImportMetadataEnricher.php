<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Services\BookEnrichmentService;

/**
 * Delegates to the existing external enrichment pipeline (Audible, Google Books, Hardcover).
 */
class ExternalImportMetadataEnricher implements ImportMetadataEnricher
{
    public function __construct(
        private readonly BookEnrichmentService $enrichmentService,
    ) {
    }

    public function enrich(array $metadata): array
    {
        return $this->enrichmentService->enrichWithExternalData($metadata, [
            'sources' => config('import_drafts.enrichment.sources'),
            'max_retries' => 1,
        ]);
    }
}
