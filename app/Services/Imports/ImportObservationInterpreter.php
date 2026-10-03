<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Models\Imports\ImportArtifact;
use App\Models\Imports\ImportDraft;
use App\Models\Imports\ImportDraftFile;
use App\Services\AIBookProcessor;
use App\Services\BookEnrichmentService;
use App\Services\BookImportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapts a draft's raw observation (tags, relative paths, display name, inline
 * NFO text) into the legacy importer's metadata helpers and produces an
 * imports.v1 recommendation with per-field provenance.
 *
 * The client supplies observations only. The server feeds them to the existing
 * book:import AI and metadata helpers before producing a review recommendation.
 */
class ImportObservationInterpreter
{
    public const SOURCE_EMBEDDED_TAG = 'embedded_tag';
    public const SOURCE_METADATA_JSON = 'metadata_json';
    public const SOURCE_AI = 'existing_importer_ai';
    public const SOURCE_NFO = 'nfo';
    public const SOURCE_AUDIO = 'audio_analysis';
    public const SOURCE_FILENAME = 'filename';
    public const SOURCE_LIBRARY_SERIES = 'library_series';
    public const SOURCE_AUTHOR_HISTORY = 'author_history';
    public const SOURCE_EXTERNAL = 'external_enrichment';
    public const SOURCE_POLICY = 'server_policy';
    public const SOURCE_USER_EDIT = 'user_edit';

    public const CONFIDENCE = [
        self::SOURCE_EMBEDDED_TAG => 1.0,
        self::SOURCE_METADATA_JSON => 1.0,
        self::SOURCE_AI => 0.75,
        self::SOURCE_NFO => 0.9,
        self::SOURCE_AUDIO => 0.7,
        self::SOURCE_FILENAME => 0.5,
        self::SOURCE_LIBRARY_SERIES => 0.7,
        self::SOURCE_AUTHOR_HISTORY => 0.7,
        self::SOURCE_EXTERNAL => 0.8,
        self::SOURCE_USER_EDIT => 1.0,
    ];

    /**
     * Short human-readable origin shown by clients ("Other suggestions").
     */
    public const SOURCE_LABELS = [
        self::SOURCE_EMBEDDED_TAG => 'file tags',
        self::SOURCE_METADATA_JSON => 'metadata.json',
        self::SOURCE_AI => 'library analysis',
        self::SOURCE_NFO => 'NFO',
        self::SOURCE_AUDIO => 'audio analysis',
        self::SOURCE_FILENAME => 'folder name',
        self::SOURCE_LIBRARY_SERIES => 'library series',
        self::SOURCE_AUTHOR_HISTORY => 'author history',
        self::SOURCE_EXTERNAL => 'online lookup',
        self::SOURCE_POLICY => 'library rules',
        self::SOURCE_USER_EDIT => 'your edit',
    ];

    private const ENRICHMENT_PROVIDER_LABELS = [
        'audible' => 'Audible',
        'google_books' => 'Google Books',
        'hardcover' => 'Hardcover',
    ];

    public const METADATA_FIELDS = ['title', 'authors', 'narrators', 'series', 'genres', 'language', 'description', 'year'];

    private const LEGACY_KEYS = [
        'title', 'author', 'narrator', 'series', 'series_number', 'genre', 'description', 'language', 'isbn', 'year',
        'publisher',
    ];

    public function __construct(
        private readonly BookImportService $importService,
        private readonly AIBookProcessor $aiProcessor,
        private readonly ImportRecommendationPolicy $policy,
        private readonly ImportMetadataEnricher $enricher,
        private readonly BookEnrichmentService $enrichmentService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function interpret(ImportDraft $draft, ?array $audioMetadata = null): array
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
        $context = $draft->client_metadata['relative_context'] ?? $sourceName;
        $metadataJson = $this->metadataJsonText($files, $artifacts);
        $observedSources = array_filter([
            self::SOURCE_METADATA_JSON => $metadataJson === null ? [] : ($this->importService->parseMetadataJsonContent($metadataJson) ?? []),
            self::SOURCE_EMBEDDED_TAG => $this->tagMetadata($audio, $sourceName . ' ' . $context),
            self::SOURCE_NFO => $this->nfoMetadata($files, $artifacts),
            self::SOURCE_FILENAME => $this->importService->parseFilenameForMetadata($sourceName),
            // Fills only what the files left empty: spoken evidence never overrides tags or the NFO.
            self::SOURCE_AUDIO => $audioMetadata,
        ]);
        $warnings = $this->observationWarnings($draft, $audio);

        $aiMetadata = (bool) config('import_drafts.ai_enabled') ? $this->analyzeWithExistingImporter(
            $sourceName,
            $context,
            $audio,
            $observedSources[self::SOURCE_NFO] ?? [],
            $metadataJson,
            $this->additionalText($files, $artifacts)
        ) : null;
        if ($aiMetadata !== null) {
            $this->preferDirectTagEvidence($aiMetadata, $observedSources, $sourceName, (string) $context, $audio);
        }
        $sources = $aiMetadata === null ? $observedSources : [self::SOURCE_METADATA_JSON => $observedSources[self::SOURCE_METADATA_JSON] ?? [], self::SOURCE_AI => $aiMetadata] + $observedSources;
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
                // book:import accepts validated external results over its AI result.
                $merged = $aiMetadata === null ? $this->importService->mergeMetadataFillMissing($merged, $enriched) : array_merge($merged, $enriched);
            }
        }

        // Same last-resort author history lookup as book:import, after enrichment.
        $genre = $merged['genre'] ?? '';
        $genre = is_array($genre) ? ($genre[0] ?? '') : $genre;
        if (in_array($genre, ['General Fiction', 'Action', 'Other', 'Unknown', ''], true)) {
            $preferred = $this->importService->getAuthorPreferredGenre($merged['author'] ?? []);
            if ($preferred !== null && !in_array($preferred, ['General Fiction', 'Action', 'Other', 'Unknown', ''], true)) {
                $merged['genre'] = [$preferred];
                $sources[self::SOURCE_AUTHOR_HISTORY] = ['genre' => [$preferred]];
            }
        }

        $directTagTitle = $observedSources[self::SOURCE_EMBEDDED_TAG]['title'] ?? null;
        $rawTagTitle = $this->legacyTags((array) ($audio->first()?->media_observation['raw_tags'] ?? []))['title'] ?? null;
        $preserveTaggedTitle = is_string($directTagTitle) && $directTagTitle !== ''
            && $directTagTitle === $rawTagTitle && ($merged['title'] ?? null) === $directTagTitle
            && empty($observedSources[self::SOURCE_METADATA_JSON]['title']);
        $normalized = $this->importService->postProcessAIResult($merged, [
            'path' => '/' . str_replace('/', ' ', $sourceName),
        ]);
        if ($preserveTaggedTitle) {
            // The legacy colon cleanup can shorten an actual tagged title even when its series is different.
            $normalized['title'] = $directTagTitle;
        }
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
     * A fresh online lookup for the book as it now stands, compared with its current details, as in the importer's
     * "Request enrichment" step. Nothing is stored: the person chooses which values to take and saves them as an
     * ordinary edit.
     *
     * @param array<string, mixed> $metadata contract-shaped current metadata
     * @param array<string, mixed> $identifiers
     * @return array<int, array{field: string, current: mixed, enriched: mixed}>
     */
    public function enrichmentComparison(array $metadata, array $identifiers = []): array
    {
        if (!(bool) config('import_drafts.enrichment.enabled')) {
            throw ImportApiException::policy(
                'enrichment_not_available',
                'This library does not look up book details online.'
            );
        }

        $legacy = $this->policy->toLegacyMetadata($metadata, $identifiers);
        try {
            $enriched = $this->enricher->enrich($legacy);
        } catch (Throwable $e) {
            Log::warning('Import draft enrichment request failed', ['error' => $e->getMessage()]);
            throw ImportApiException::policy(
                'enrichment_unavailable',
                'Online book details could not be looked up right now.'
            );
        }
        if (!$this->enrichmentService->isValidEnrichment($legacy, $enriched)) {
            throw ImportApiException::policy(
                'enrichment_no_match',
                'Online book details did not match this book.'
            );
        }

        $proposed = $this->contractMetadata(array_intersect_key($enriched, array_flip(self::LEGACY_KEYS)));
        $rows = [];
        foreach (['title', 'authors', 'narrators', 'series', 'year', 'genres', 'description'] as $field) {
            $value = $proposed[$field] ?? null;
            if ($value === null || $value === [] || $value === '' || $value === ($metadata[$field] ?? null)) {
                continue;
            }
            $rows[] = ['field' => $field, 'current' => $metadata[$field] ?? null, 'enriched' => $value];
        }

        return $rows;
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
            'year' => $this->yearOrNull($legacy['year'] ?? null),
            'cover_artifact_id' => $coverArtifactId,
        ];
    }

    private function yearOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            $year = $value;
        } elseif (is_string($value) && preg_match('/^\s*(\d{4})(?:\D|$)/', $value, $matches) === 1) {
            $year = (int) $matches[1];
        } else {
            return null;
        }

        return $year >= 1000 && $year <= 9999 ? $year : null;
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
            $winner = array_search($final, array_column($entries, 'value'), false);
            if ($winner !== false && $winner !== 0) {
                array_unshift($entries, ...array_splice($entries, $winner, 1));
            } elseif ($winner === false) {
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
     * Sends a short audio sample the client supplied through the existing audio-analysis AI path.
     *
     * @return array<string, mixed>|null legacy-shaped metadata, or null when nothing usable was heard
     */
    public function analyzeAudioSample(string $path, string $hint): ?array
    {
        $result = $this->aiProcessor->processAudioSample($path, $hint);
        if (!is_array($result)) {
            return null;
        }
        $metadata = array_intersect_key($result, array_flip(self::LEGACY_KEYS));
        foreach (['author', 'narrator'] as $key) {
            if (isset($metadata[$key]) && is_string($metadata[$key])) {
                $metadata[$key] = $this->importService->splitMultiValueNameTag($metadata[$key]);
            }
        }
        if (isset($metadata['genre']) && is_string($metadata['genre'])) {
            $metadata['genre'] = [$metadata['genre']];
        }
        $metadata = array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);

        return $metadata === [] ? null : $metadata;
    }

    /**
     * book:import takes the album tag as the title, but an album tag is often the wrong book (a series
     * name, or the previous book of the series). When the title tag matches the folder or file name
     * better than the album-derived title does, the title tag is the better evidence.
     *
     * @param array<string, mixed> $metadata extractMetadataFromFileTags output
     * @param array<string, mixed> $tags legacyTags output
     * @return array<string, mixed>
     */
    public function reconcileTagTitle(array $metadata, array $tags, string $sourceName): array
    {
        $titleTag = isset($tags['title']) && is_string($tags['title']) ? trim($tags['title']) : '';
        $current = isset($metadata['title']) && is_string($metadata['title']) ? $metadata['title'] : '';
        if ($titleTag === '' || $current === '' || strcasecmp($titleTag, $current) === 0) {
            return $metadata;
        }

        $nameTokens = $this->titleTokens($sourceName);
        if ($nameTokens === []) {
            return $metadata;
        }
        $overlap = static fn (string $title): int => count(array_intersect(
            self::titleTokensOf($title),
            $nameTokens
        ));

        if ($overlap($titleTag) > $overlap($current)) {
            $metadata['title'] = $titleTag;
        }

        return $metadata;
    }

    /** @return array<int, string> */
    private function titleTokens(string $name): array
    {
        return self::titleTokensOf(pathinfo($name, PATHINFO_FILENAME) ?: $name);
    }

    /** @return array<int, string> */
    private static function titleTokensOf(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $word): bool => !ctype_digit($word) && !in_array($word, ['the', 'a', 'an', 'of', 'and', 'book'], true)
        )));
    }

    /**
     * @param Collection<int, ImportDraftFile> $audio
     * @return array<string, mixed>
     */
    private function tagMetadata(Collection $audio, string $sourceName): array
    {
        foreach ($audio as $file) {
            $tags = $this->legacyTags((array) ($file->media_observation['raw_tags'] ?? []));
            if ($tags === []) {
                continue;
            }
            $metadata = $this->importService->extractMetadataFromFileTags([basename($file->relative_path) => $tags]);
            $metadata = $this->reconcileTagTitle($metadata, $tags, $sourceName);
            if (isset($tags['language']) && is_string($tags['language'])) {
                $metadata['language'] = $tags['language'];
            }

            return $metadata;
        }

        return [];
    }

    /**
     * The existing importer analysis can echo a folder name as a title and mistake the tagged title for a
     * series. When that happens, keep its other findings but let the already reconciled file tags win these fields.
     *
     * @param array<string, mixed> $aiMetadata
     * @param array<string, array<string, mixed>> $observedSources
     * @param Collection<int, ImportDraftFile> $audio
     */
    private function preferDirectTagEvidence(array &$aiMetadata, array &$observedSources, string $sourceName, string $context, Collection $audio): void
    {
        $tagTitle = $observedSources[self::SOURCE_EMBEDDED_TAG]['title'] ?? null;
        $aiTitle = $aiMetadata['title'] ?? null;
        if (!is_string($tagTitle) || !is_string($aiTitle) || $tagTitle === '') {
            return;
        }

        $folderNames = array_unique(array_merge([$sourceName], explode('/', $context)));
        $folderTitles = array_map(
            static fn (string $name): string => trim((string) preg_replace('/^\s*\d+\s*[-._]\s*/', '', pathinfo($name, PATHINFO_FILENAME) ?: $name)),
            $folderNames
        );
        if (!in_array($aiTitle, $folderTitles, true) || strcasecmp($tagTitle, $aiTitle) === 0) {
            return;
        }

        unset($aiMetadata['title']);
        $tags = $this->legacyTags((array) ($audio->first()?->media_observation['raw_tags'] ?? []));
        $album = $tags['album'] ?? null;
        $track = $tags['track'] ?? null;
        if (
            is_string($album) && $album !== '' && strcasecmp($album, $tagTitle) !== 0
            && ($aiMetadata['series'] ?? null) === $tagTitle && is_scalar($track) && preg_match('/^\d+$/', (string) $track) === 1
        ) {
            $observedSources[self::SOURCE_EMBEDDED_TAG]['series'] = $album;
            $observedSources[self::SOURCE_EMBEDDED_TAG]['series_number'] = (int) $track;
            unset($aiMetadata['series'], $aiMetadata['series_number']);
        }
    }

    /**
     * Send observed facts through the same analysis path as book:import. Audio
     * bytes are not needed; the client may include small NFO text artifacts.
     *
     * @param Collection<int, ImportDraftFile> $audio
     * @param array<string, mixed> $nfo
     * @return array<string, mixed>|null
     */
    private function analyzeWithExistingImporter(
        string $sourceName,
        string $context,
        Collection $audio,
        array $nfo,
        ?string $metadataJson,
        array $additionalText = []
    ): ?array {
        $tags = [];
        $names = [];
        foreach ($audio as $file) {
            $names[] = $file->relative_path;
            $values = $this->legacyTags((array) ($file->media_observation['raw_tags'] ?? []));
            if ($values !== []) {
                $tags[$file->relative_path] = $values;
            }
        }

        return $this->importService->processWithAI([
            'path' => '/observed/' . $context,
            'name' => $sourceName,
            'files' => $names,
            'observed_file_tags' => $tags,
            'observed_nfo_data' => $nfo ?: null,
            'observed_metadata_json' => $metadataJson,
            'observed_additional_text' => $additionalText,
        ], $this->aiProcessor);
    }

    /**
     * Small text files the client inlined, other than the NFO and metadata.json that are parsed on their own.
     *
     * @param Collection<int, ImportDraftFile> $files
     * @param Collection<int, ImportArtifact> $artifacts
     * @return array<string, string>
     */
    private function additionalText(Collection $files, Collection $artifacts): array
    {
        $text = [];
        foreach ($files as $file) {
            if ($file->text_artifact_id === null || $file->role === 'nfo' || strtolower(basename($file->relative_path)) === 'metadata.json') {
                continue;
            }
            $inline = $artifacts->firstWhere('artifact_id', $file->text_artifact_id)?->inline_text;
            if (is_string($inline) && trim($inline) !== '') {
                $text[$file->relative_path] = $inline;
            }
        }

        return $text;
    }

    /** @param Collection<int, ImportDraftFile> $files
     *  @param Collection<int, ImportArtifact> $artifacts
     */
    private function metadataJsonText(Collection $files, Collection $artifacts): ?string
    {
        $file = $files->first(static fn (ImportDraftFile $file): bool =>
            strtolower(basename($file->relative_path)) === 'metadata.json' && $file->text_artifact_id !== null);

        return $file === null ? null : $artifacts->firstWhere('artifact_id', $file->text_artifact_id)?->inline_text;
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
        if ($audio->count() === 1 && $first !== null && $this->isFileName($draft->source_display_name)) {
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

        if (!$this->enrichmentService->isValidEnrichment($metadata, $enriched)) {
            $warnings[] = [
                'id' => 'enrichment_mismatch',
                'code' => 'enrichment_mismatch',
                'message' => 'Online book details did not match this book, so they were ignored.',
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
