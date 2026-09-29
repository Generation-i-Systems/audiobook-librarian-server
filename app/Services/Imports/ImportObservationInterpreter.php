<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Imports\ImportArtifact;
use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportDraftFile;
use App\Services\BookImportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapts a draft's raw observation (tags, relative paths, display name, inline
 * NFO text) into the legacy importer's metadata helpers and produces an
 * imports.v1 recommendation with per-field provenance.
 *
 * Precedence is the legacy fill-missing order: embedded tags, then NFO, then the
 * file/folder name, then (optionally) library series genre and external
 * enrichment. The merged result then goes through the same pre-review
 * normalization book:import applies (BookImportService::postProcessAIResult).
 */
class ImportObservationInterpreter
{
    public const SOURCE_EMBEDDED_TAG = 'embedded_tag';
    public const SOURCE_NFO = 'nfo';
    public const SOURCE_FILENAME = 'filename';
    public const SOURCE_LIBRARY_SERIES = 'library_series';
    public const SOURCE_EXTERNAL = 'external_enrichment';
    public const SOURCE_POLICY = 'server_policy';
    public const SOURCE_USER_EDIT = 'user_edit';

    public const CONFIDENCE = [
        self::SOURCE_EMBEDDED_TAG => 1.0,
        self::SOURCE_NFO => 0.9,
        self::SOURCE_FILENAME => 0.5,
        self::SOURCE_LIBRARY_SERIES => 0.7,
        self::SOURCE_EXTERNAL => 0.8,
        self::SOURCE_USER_EDIT => 1.0,
    ];

    /**
     * Short human-readable origin shown by clients ("Other suggestions").
     */
    public const SOURCE_LABELS = [
        self::SOURCE_EMBEDDED_TAG => 'file tags',
        self::SOURCE_NFO => 'NFO',
        self::SOURCE_FILENAME => 'folder name',
        self::SOURCE_LIBRARY_SERIES => 'library series',
        self::SOURCE_EXTERNAL => 'online lookup',
        self::SOURCE_POLICY => 'library rules',
        self::SOURCE_USER_EDIT => 'your edit',
    ];

    private const ENRICHMENT_PROVIDER_LABELS = [
        'audible' => 'Audible',
        'google_books' => 'Google Books',
        'hardcover' => 'Hardcover',
    ];

    public const METADATA_FIELDS = ['title', 'authors', 'narrators', 'series', 'genres', 'language', 'description'];

    private const LEGACY_KEYS = [
        'title', 'author', 'narrator', 'series', 'series_number', 'genre', 'description', 'language', 'isbn', 'year',
        'publisher',
    ];

    public function __construct(
        private readonly BookImportService $importService,
        private readonly ImportRecommendationPolicy $policy,
        private readonly ImportMetadataEnricher $enricher,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function interpret(ImportDraft $draft): array
    {
        $files = $draft->files()->get();
        $audio = $files->where('role', 'audio')->sortBy('relative_path', SORT_NATURAL | SORT_FLAG_CASE)->values();
        if ($audio->isEmpty()) {
            throw ImportInterpretationException::noAudioFiles();
        }
        $artifacts = $draft->artifacts()->get();

        $sourceName = $this->sourceName($draft, $audio);
        $labels = self::SOURCE_LABELS;
        if ($sourceName !== $draft->source_display_name || $this->isFileName($sourceName)) {
            $labels[self::SOURCE_FILENAME] = 'file name';
        }
        $sources = array_filter([
            self::SOURCE_EMBEDDED_TAG => $this->tagMetadata($audio),
            self::SOURCE_NFO => $this->nfoMetadata($files, $artifacts),
            self::SOURCE_FILENAME => $this->importService->parseFilenameForMetadata($sourceName),
        ]);
        $warnings = $this->observationWarnings($draft, $audio);

        $merged = [];
        foreach ($sources as $metadata) {
            $merged = $this->importService->mergeMetadataFillMissing($merged, $metadata);
        }

        if (empty($merged['genre'])) {
            $seriesGenre = $this->importService->lookupGenreFromExistingSeries($merged);
            if ($seriesGenre !== null) {
                $sources[self::SOURCE_LIBRARY_SERIES] = ['genre' => [$seriesGenre]];
                $merged['genre'] = [$seriesGenre];
            }
        }

        if ($this->enrichmentRequested($draft)) {
            $enriched = $this->enrich($merged, $warnings, $labels);
            if ($enriched !== []) {
                $sources[self::SOURCE_EXTERNAL] = $enriched;
                $merged = $this->importService->mergeMetadataFillMissing($merged, $enriched);
            }
        }

        $normalized = $this->importService->postProcessAIResult($merged, [
            'path' => '/' . str_replace('/', ' ', $sourceName),
        ]);
        $metadata = $this->contractMetadata($normalized, $this->coverArtifactId($files, $audio, $artifacts));
        $identifiers = ['isbn' => $this->stringOrNull($normalized['isbn'] ?? null)];
        $derived = $this->policy->derive($draft, $metadata, $identifiers, $warnings);

        return [
            'metadata' => $metadata,
            'field_provenance' => $this->provenance($sources, $metadata, $labels),
            'duplicate_candidates' => $derived['duplicate_candidates'],
            'target_candidates' => $derived['target_candidates'],
            'warnings' => $derived['warnings'],
            'required_decisions' => $derived['required_decisions'],
            'identifiers' => $identifiers,
        ];
    }

    /**
     * Convert legacy-shaped metadata into the contract `Metadata` object.
     *
     * @param array<string, mixed> $legacy
     * @return array<string, mixed>
     */
    public function contractMetadata(array $legacy, ?string $coverArtifactId = null): array
    {
        $seriesName = $this->stringOrNull($legacy['series'] ?? null);
        $description = $this->stringOrNull($legacy['description'] ?? null);

        return [
            'title' => $this->stringOrNull($legacy['title'] ?? null),
            'authors' => $this->nameList($legacy['author'] ?? []),
            'narrators' => $this->nameList($legacy['narrator'] ?? []),
            'series' => $seriesName === null ? null : [
                'name' => $seriesName,
                'number' => $this->seriesNumber($legacy['series_number'] ?? null),
            ],
            'genres' => $this->stringList($legacy['genre'] ?? []),
            'tags' => [],
            'language' => $this->stringOrNull($legacy['language'] ?? null),
            'description' => $description === null ? null : $this->importService->cleanDescription($description),
            'cover_artifact_id' => $coverArtifactId,
        ];
    }

    /**
     * Each field lists every source that proposed a value, the winning value first.
     * When normalization changed the winning value, a server_policy entry leads.
     *
     * @param array<string, array<string, mixed>> $sources keyed by source id
     * @param array<string, mixed> $metadata
     * @param array<string, string> $labels source id => human-readable label
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function provenance(array $sources, array $metadata, array $labels = self::SOURCE_LABELS): array
    {
        $provenance = [];
        foreach (self::METADATA_FIELDS as $field) {
            $entries = [];
            foreach ($sources as $source => $legacy) {
                $value = $this->contractMetadata($legacy)[$field];
                if ($value === null || $value === []) {
                    continue;
                }
                $entries[] = $this->provenanceEntry($source, $value, self::CONFIDENCE[$source], $labels);
            }
            $final = $metadata[$field];
            if ($final === null || $final === []) {
                continue;
            }
            if ($entries === [] || $entries[0]['value'] != $final) {
                array_unshift($entries, $this->provenanceEntry(
                    self::SOURCE_POLICY,
                    $final,
                    $entries[0]['confidence'] ?? self::CONFIDENCE[self::SOURCE_FILENAME],
                    $labels
                ));
            }
            $provenance[$field] = $entries;
        }

        return $provenance;
    }

    /**
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    public function provenanceEntry(
        string $sourceId,
        mixed $value,
        float $confidence,
        array $labels = self::SOURCE_LABELS
    ): array {
        return [
            'source' => $labels[$sourceId] ?? $sourceId,
            'source_id' => $sourceId,
            'value' => $value,
            'confidence' => $confidence,
        ];
    }

    /**
     * Raw tags arrive as name => [values]; the legacy reader expects getID3-style
     * name => value, keeping multiple artists/narrators as a list.
     *
     * @param array<string, mixed> $rawTags
     * @return array<string, mixed>
     */
    public function legacyTags(array $rawTags): array
    {
        $tags = [];
        foreach ($rawTags as $name => $values) {
            $values = array_values(array_filter(
                array_map(
                    static fn (mixed $value): string => is_scalar($value) ? trim((string) $value) : '',
                    (array) $values
                ),
                static fn (string $value): bool => $value !== ''
            ));
            if ($values === []) {
                continue;
            }
            $key = strtolower((string) $name);
            $tags[$key] = in_array($key, ['artist', 'narrator'], true) && count($values) > 1 ? $values : $values[0];
        }

        return $tags;
    }

    /**
     * @param Collection<int, ImportDraftFile> $audio
     * @return array<string, mixed>
     */
    private function tagMetadata(Collection $audio): array
    {
        foreach ($audio as $file) {
            $tags = $this->legacyTags((array) ($file->media_observation['raw_tags'] ?? []));
            if ($tags === []) {
                continue;
            }
            $metadata = $this->importService->extractMetadataFromFileTags([basename($file->relative_path) => $tags]);
            if (isset($tags['language']) && is_string($tags['language'])) {
                $metadata['language'] = $tags['language'];
            }

            return $metadata;
        }

        return [];
    }

    /**
     * @param Collection<int, ImportDraftFile> $files
     * @param Collection<int, ImportArtifact> $artifacts
     * @return array<string, mixed>
     */
    private function nfoMetadata(Collection $files, Collection $artifacts): array
    {
        $nfoArtifactIds = $files->where('role', 'nfo')->pluck('text_artifact_id')->filter()->all();
        $artifact = $artifacts->first(
            static fn (ImportArtifact $a): bool => $a->inline_text !== null
                && in_array($a->artifact_id, $nfoArtifactIds, true)
        );
        if ($artifact === null || trim((string) $artifact->inline_text) === '') {
            return [];
        }

        $nfo = $this->importService->parseNfoContent((string) $artifact->inline_text);
        foreach (['author', 'narrator'] as $key) {
            if (isset($nfo[$key]) && is_string($nfo[$key])) {
                $nfo[$key] = $this->importService->splitMultiValueNameTag($nfo[$key]);
            }
        }
        if (isset($nfo['genre']) && is_string($nfo['genre'])) {
            $nfo['genre'] = [$nfo['genre']];
        }
        if (isset($nfo['series_number'])) {
            $nfo['series_number'] = $this->seriesNumber($nfo['series_number']);
        }

        return array_intersect_key($nfo, array_flip(self::LEGACY_KEYS));
    }

    /**
     * A single root-level audio file is named by its filename; anything else by the
     * folder the user picked, matching how book:import treats files and directories.
     *
     * @param Collection<int, ImportDraftFile> $audio
     */
    private function sourceName(ImportDraft $draft, Collection $audio): string
    {
        $first = $audio->first();
        if ($audio->count() === 1 && $first !== null && !str_contains($first->relative_path, '/')) {
            return $first->relative_path;
        }

        return $draft->source_display_name;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, array<string, mixed>> $warnings
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    private function enrich(array $metadata, array &$warnings, array &$labels): array
    {
        try {
            $enriched = $this->enricher->enrich($metadata);
        } catch (Throwable $e) {
            Log::warning('Import draft enrichment failed', ['error' => $e->getMessage()]);
            $enriched = null;
        }
        if ($enriched === null) {
            $warnings[] = [
                'id' => 'enrichment_unavailable',
                'code' => 'enrichment_unavailable',
                'message' => 'Online book details could not be looked up. The recommendation uses the files only.',
                'requires_acknowledgment' => false,
            ];

            return [];
        }

        $providers = array_keys(array_filter(
            (array) ($enriched['_enrichment_results'] ?? []),
            static fn (mixed $result): bool => $result === 'success'
        ));
        if (count($providers) === 1 && isset(self::ENRICHMENT_PROVIDER_LABELS[$providers[0]])) {
            $labels[self::SOURCE_EXTERNAL] = self::ENRICHMENT_PROVIDER_LABELS[$providers[0]];
        }

        return array_intersect_key($enriched, array_flip(self::LEGACY_KEYS));
    }

    private function isFileName(string $name): bool
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return in_array($extension, (array) config('import_drafts.accepted_audio_extensions'), true);
    }

    private function enrichmentRequested(ImportDraft $draft): bool
    {
        $requested = (array) ($draft->analysis_request['requested'] ?? []);

        return (bool) config('import_drafts.enrichment.enabled') && in_array('external_enrichment', $requested, true);
    }

    /**
     * @param Collection<int, ImportDraftFile> $audio
     * @return array<int, array<string, mixed>>
     */
    private function observationWarnings(ImportDraft $draft, Collection $audio): array
    {
        $warnings = [];
        foreach (array_values($draft->source_warnings ?? []) as $index => $text) {
            $warnings[] = [
                'id' => 'source_warning_' . ($index + 1),
                'code' => 'source_incomplete',
                'message' => 'Some files could not be read on your computer: ' . $text,
                'requires_acknowledgment' => true,
            ];
        }
        foreach ($audio as $file) {
            foreach (array_values((array) ($file->media_observation['warnings'] ?? [])) as $index => $text) {
                $warnings[] = [
                    'id' => 'media_warning_' . $file->file_id . '_' . ($index + 1),
                    'code' => 'media_unreadable',
                    'message' => $file->relative_path . ': '
                        . (is_scalar($text) ? (string) $text : 'unreadable details'),
                    'requires_acknowledgment' => false,
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param Collection<int, ImportDraftFile> $files
     * @param Collection<int, ImportDraftFile> $audio
     * @param Collection<int, ImportArtifact> $artifacts
     */
    private function coverArtifactId(Collection $files, Collection $audio, Collection $artifacts): ?string
    {
        $candidates = $files->where('role', 'cover')->pluck('image_artifact_id')->all();
        foreach ($audio as $file) {
            $candidates[] = $file->media_observation['embedded_cover']['artifact_id'] ?? null;
        }
        $candidates[] = $artifacts->firstWhere('kind', 'image')?->artifact_id;

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function nameList(mixed $value): array
    {
        if (is_string($value)) {
            $value = $this->importService->splitMultiValueNameTag($value);
        }

        return $this->stringList($value);
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $clean = [];
        foreach ($values as $item) {
            $item = $this->stringOrNull($item);
            if ($item !== null && !in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }

        return $clean;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function seriesNumber(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (!is_string($value) || !is_numeric(trim($value))) {
            return null;
        }
        $value = trim($value);

        return str_contains($value, '.') ? (float) $value : (int) $value;
    }
}
