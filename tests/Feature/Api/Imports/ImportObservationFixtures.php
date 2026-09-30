<?php

declare(strict_types=1);

namespace Tests\Feature\Api\Imports;

use Illuminate\Support\Str;

/**
 * Fixture-like SourceObservation builders for imports.v1 tests.
 */
final class ImportObservationFixtures
{
    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $artifacts
     * @param array<int, string> $warnings
     * @param array<int, string> $requested
     * @return array<string, mixed>
     */
    public static function observation(
        string $displayName,
        array $files,
        array $artifacts = [],
        array $warnings = [],
        array $requested = ['tag_normalization', 'duplicate_check', 'path_recommendation'],
    ): array {
        return [
            'contract_version' => 'imports.v1',
            'client' => [
                'client_id' => (string) Str::uuid(),
                'client_kind' => 'kotlin_desktop',
                'client_version' => '0.1.0',
                'observation_schema_version' => 1,
            ],
            'source' => [
                'display_name' => $displayName,
                'mode' => 'upload',
                'warnings' => $warnings,
                'files' => $files,
                'artifacts' => $artifacts,
            ],
            'analysis' => ['requested' => $requested],
        ];
    }

    /**
     * @param array<string, array<int, string>> $rawTags
     * @param array<int, string> $mediaWarnings
     * @return array<string, mixed>
     */
    public static function audioFile(string $fileId, string $relativePath, array $rawTags = [], array $mediaWarnings = []): array
    {
        return [
            'file_id' => $fileId,
            'relative_path' => $relativePath,
            'role' => 'audio',
            'bytes' => 1000,
            'sha256' => null,
            'media_observation' => [
                'container' => strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)),
                'duration_ms' => 60000,
                'raw_tags' => (object) $rawTags,
                'warnings' => $mediaWarnings,
            ],
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function nfoFileAndArtifact(string $text): array
    {
        $hash = hash('sha256', $text);

        return [
            [
                'file_id' => 'f_nfo',
                'relative_path' => 'metadata.nfo',
                'role' => 'nfo',
                'bytes' => strlen($text),
                'sha256' => $hash,
                'text_artifact_id' => 'nfo_01',
            ],
            [
                'artifact_id' => 'nfo_01',
                'kind' => 'text',
                'media_type' => 'text/plain',
                'sha256' => $hash,
                'inline_utf8' => $text,
            ],
        ];
    }

    /**
     * An observation whose tags, when interpreted, recommend "Dust Road" by Jane Author.
     *
     * @return array<string, mixed>
     */
    public static function dustRoadObservation(array $warnings = []): array
    {
        return self::observation('Dust Road', [
            self::audioFile('f_01', 'Dust Road.m4b', [
                'album' => ['Dust Road'],
                'artist' => ['Jane Author'],
                'genre' => ['Fantasy'],
            ]),
        ], [], $warnings);
    }
}
