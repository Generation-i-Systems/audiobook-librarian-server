<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Illuminate\Support\Facades\Validator;

/**
 * Validates an imports.v1 SourceObservation and returns it with normalized paths.
 */
class SourceObservationValidator
{
    private const SHA256 = 'regex:/^[a-f0-9]{64}$/';

    public function __construct(
        private readonly ImportRelativePathNormalizer $pathNormalizer,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload): array
    {
        $validator = Validator::make($payload, $this->rules());
        if ($validator->fails()) {
            throw ImportApiException::validation(
                'The import details sent by the app were not valid.',
                ['fields' => $validator->errors()->toArray()]
            );
        }

        $observation = $validator->validated();
        $this->assertTransferModeOffered((string) $observation['source']['mode']);
        $observation['source']['files'] = $this->normalizeFiles($observation['source']['files']);
        $this->assertBoundedObservations($observation['source']['files']);
        $this->assertBoundedInlineArtifacts($observation['source']['artifacts'] ?? []);

        return $observation;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $maxFiles = (int) config('import_drafts.max_files_per_draft');

        return [
            'contract_version' => 'required|string|in:' . config('import_drafts.contract_version'),
            'client' => 'required|array',
            'client.client_id' => 'required|uuid',
            'client.client_kind' => 'required|string|in:kotlin_tui,kotlin_desktop,php_tui',
            'client.client_version' => 'required|string|max:64',
            'client.observation_schema_version' => [
                'required',
                'integer',
                'in:' . implode(',', config('import_drafts.observation_schema_versions')),
            ],
            'client.capabilities' => 'sometimes|array|max:32',
            'client.capabilities.*' => 'string|max:64',
            'source' => 'required|array',
            'source.display_name' => 'required|string|max:500',
            'source.mode' => 'required|string|in:upload,shared_stage',
            'source.root_fingerprint' => 'sometimes|nullable|string|max:512',
            'source.warnings' => 'sometimes|array|max:500',
            'source.warnings.*' => 'string|max:1024',
            'source.files' => 'required|array|min:1|max:' . $maxFiles,
            'source.files.*.file_id' => 'required|string|max:128|distinct',
            'source.files.*.relative_path' => 'required|string|max:1024',
            'source.files.*.role' => 'required|string|in:audio,cover,nfo,ebook,sidecar,other',
            'source.files.*.bytes' => 'required|integer|min:0',
            'source.files.*.modified_at' => 'sometimes|nullable|date',
            'source.files.*.sha256' => ['sometimes', 'nullable', 'string', self::SHA256],
            'source.files.*.fingerprint' => 'sometimes|nullable|array',
            'source.files.*.fingerprint.algorithm' => 'required_with:source.files.*.fingerprint|string|max:64',
            'source.files.*.fingerprint.value' => 'required_with:source.files.*.fingerprint|string|max:256',
            'source.files.*.media_observation' => 'sometimes|nullable|array',
            'source.files.*.text_artifact_id' => 'sometimes|nullable|string|max:128',
            'source.files.*.image_artifact_id' => 'sometimes|nullable|string|max:128',
            'source.artifacts' => 'sometimes|array|max:64',
            'source.artifacts.*.artifact_id' => 'required|string|max:128|distinct',
            'source.artifacts.*.kind' => 'required|string|in:image,text,audio_sample',
            'source.artifacts.*.media_type' => 'required|string|max:128',
            'source.artifacts.*.bytes' => 'sometimes|nullable|integer|min:0',
            'source.artifacts.*.sha256' => ['required', 'string', self::SHA256],
            'source.artifacts.*.inline_utf8' => 'sometimes|nullable|string',
            'source.artifacts.*.upload_reference' => 'sometimes|nullable|string|max:256',
            'analysis' => 'required|array',
            'analysis.requested' => 'present|array',
            'analysis.requested.*' => 'string|in:tag_normalization,external_enrichment,duplicate_check,path_recommendation',
            'analysis.local_ai_artifacts' => 'sometimes|array|max:0',
        ];
    }

    private function assertTransferModeOffered(string $mode): void
    {
        if (!in_array($mode, config('import_drafts.transfer_modes'), true)) {
            throw ImportApiException::validation(
                'This server does not accept that way of sending files.',
                ['source.mode' => $mode]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFiles(array $files): array
    {
        $seenKeys = [];
        foreach ($files as $index => $file) {
            $normalizedPath = $this->pathNormalizer->normalize((string) $file['relative_path']);
            $key = $this->pathNormalizer->key($normalizedPath);
            if (isset($seenKeys[$key])) {
                throw ImportApiException::validation(
                    'Two files in this import have the same name.',
                    ['relative_path' => $normalizedPath]
                );
            }
            $seenKeys[$key] = true;
            $files[$index]['relative_path'] = $normalizedPath;
            $files[$index]['normalized_path_key'] = $key;
        }

        return $files;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function assertBoundedObservations(array $files): void
    {
        $limit = (int) config('import_drafts.max_media_observation_bytes');
        foreach ($files as $file) {
            if (!isset($file['media_observation'])) {
                continue;
            }
            if (strlen((string) json_encode($file['media_observation'])) > $limit) {
                throw ImportApiException::validation(
                    'The details read from one file were too large.',
                    ['relative_path' => $file['relative_path']]
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $artifacts
     */
    private function assertBoundedInlineArtifacts(array $artifacts): void
    {
        $limit = (int) config('import_drafts.max_inline_text_bytes');
        foreach ($artifacts as $artifact) {
            $inline = $artifact['inline_utf8'] ?? null;
            if ($inline === null) {
                continue;
            }
            if ($artifact['kind'] !== 'text' || strlen($inline) > $limit) {
                throw ImportApiException::validation(
                    'An attached text file was too large or not text.',
                    ['artifact_id' => $artifact['artifact_id']]
                );
            }
            if (hash('sha256', $inline) !== $artifact['sha256']) {
                throw ImportApiException::validation(
                    'An attached text file did not match its checksum.',
                    ['artifact_id' => $artifact['artifact_id']]
                );
            }
        }
    }
}
