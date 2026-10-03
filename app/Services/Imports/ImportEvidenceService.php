<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Enums\ImportDraftState;
use App\Jobs\ResumeImportDraftInterpretationJob;
use App\Models\Imports\ImportDraft;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Back-and-forth for evidence the server wants from the client during interpretation: a short audio
 * sample for the AI when tags, NFO and lookups leave the title or author unproven.
 */
class ImportEvidenceService
{
    public const CAPABILITY = 'audio_snippet';

    /** Sources that guess rather than prove a title or author. */
    private const UNPROVEN_SOURCES = [
        ImportObservationInterpreter::SOURCE_FILENAME,
        ImportObservationInterpreter::SOURCE_AI,
        ImportObservationInterpreter::SOURCE_POLICY,
    ];

    public function __construct(
        private readonly ImportDraftService $drafts,
        private readonly ImportStagingStore $staging,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('import_drafts.audio_evidence.enabled') && (bool) config('import_drafts.ai_enabled');
    }

    /**
     * Ask only when the client can answer, there is audio to sample, and the title or the authors are not
     * proven by a real source (tags, NFO, online lookup, an earlier edit) at the configured confidence.
     *
     * @param array<string, mixed> $recommendation
     */
    public function shouldRequest(ImportDraft $draft, array $recommendation): bool
    {
        if (!$this->isEnabled() || ($draft->evidence_requests ?? []) !== []) {
            return false;
        }
        if (!in_array(self::CAPABILITY, (array) ($draft->client_metadata['capabilities'] ?? []), true)) {
            return false;
        }
        if ($this->firstAudioFile($draft) === null) {
            return false;
        }
        $provenance = (array) ($recommendation['field_provenance'] ?? []);

        return !$this->proven((array) ($provenance['title'] ?? []))
            || !$this->proven((array) ($provenance['authors'] ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    public function newRequest(ImportDraft $draft): array
    {
        $file = $this->firstAudioFile($draft);
        $config = (array) config('import_drafts.audio_evidence');

        return [
            'id' => 'ev_' . Str::random(16),
            'type' => 'audio_snippet',
            'status' => 'pending',
            'file_id' => $file?->file_id,
            'start_ms' => 0,
            'duration_ms' => (int) $config['snippet_seconds'] * 1000,
            'max_bytes' => (int) $config['max_bytes'],
            'media_types' => array_values((array) $config['media_types']),
            'expires_at' => now()->addSeconds((int) $config['request_ttl_seconds'])->toIso8601ZuluString(),
        ];
    }

    /**
     * Records the client's answer and lets interpretation continue. Repeating the same answer returns the
     * draft unchanged; a different answer to a closed request conflicts.
     *
     * @param array<string, mixed> $body
     */
    public function answer(User $user, string $publicId, string $requestId, array $body): ImportDraft
    {
        $draft = $this->drafts->findForUser($user, $publicId);
        [$status, $sha256, $bytes] = $this->validatedAnswer($body);

        $resume = DB::transaction(function () use ($draft, $requestId, $status, $sha256, $bytes): bool {
            /** @var ImportDraft $locked */
            $locked = ImportDraft::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $requests = array_values((array) ($locked->evidence_requests ?? []));
            $index = $this->indexOf($requests, $requestId);
            if ($index === null) {
                throw new ImportApiException(404, 'evidence_request_not_found', 'The library is not waiting for that.');
            }
            $request = $requests[$index];

            if ($request['status'] !== 'pending') {
                if ($this->sameAnswer($request, $status, $sha256)) {
                    return false;
                }
                throw $this->closed();
            }
            if ($locked->state !== ImportDraftState::INTERPRETING || $this->due($request)) {
                throw $this->closed();
            }

            if ($status === 'provided') {
                $this->staging->writeEvidence($locked->public_id, $requestId, (string) $bytes);
            }
            $requests[$index] = $request + [];
            $requests[$index]['status'] = $status === 'provided' ? 'answered' : 'unavailable';
            $requests[$index]['sha256'] = $sha256;
            $requests[$index]['answered_at'] = now()->toIso8601ZuluString();
            $locked->evidence_requests = $requests;
            $locked->revision = $locked->revision + 1;
            $locked->save();
            $this->drafts->recordEvent($locked, 'evidence_answered', [
                'request_id' => $requestId,
                'status' => $requests[$index]['status'],
            ]);

            return true;
        });

        if ($resume) {
            ResumeImportDraftInterpretationJob::dispatch($draft->id);
        }

        return ImportDraft::query()->whereKey($draft->id)->with('files')->firstOrFail();
    }

    /**
     * @param array<string, mixed> $request
     */
    public function due(array $request): bool
    {
        return Carbon::parse((string) $request['expires_at'])->lessThanOrEqualTo(now());
    }

    /**
     * @param array<int, array<string, mixed>> $provenance
     */
    private function proven(array $provenance): bool
    {
        $threshold = (float) config('import_drafts.audio_evidence.proven_confidence');
        foreach ($provenance as $entry) {
            if (
                !in_array($entry['source_id'] ?? null, self::UNPROVEN_SOURCES, true)
                && (float) ($entry['confidence'] ?? 0) >= $threshold
            ) {
                return true;
            }
        }

        return false;
    }

    private function firstAudioFile(ImportDraft $draft): ?\App\Models\Imports\ImportDraftFile
    {
        return $draft->files()->where('role', 'audio')->get()
            ->sortBy('relative_path', SORT_NATURAL | SORT_FLAG_CASE)->first();
    }

    /**
     * @param array<int, array<string, mixed>> $requests
     */
    private function indexOf(array $requests, string $requestId): ?int
    {
        foreach ($requests as $index => $request) {
            if (($request['id'] ?? null) === $requestId) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function sameAnswer(array $request, string $status, ?string $sha256): bool
    {
        return $status === 'provided' ? $request['status'] === 'answered' && ($request['sha256'] ?? null) === $sha256 : $request['status'] === 'unavailable';
    }

    private function closed(): ImportApiException
    {
        return new ImportApiException(409, 'evidence_request_closed', 'The library is no longer waiting for that.');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: string, 1: ?string, 2: ?string} status (provided|unavailable), sha256, decoded bytes
     */
    private function validatedAnswer(array $body): array
    {
        $status = $body['status'] ?? null;
        if ($status === 'unavailable') {
            return ['unavailable', null, null];
        }
        if ($status !== 'provided') {
            throw ImportApiException::validation('The answer status must be "provided" or "unavailable".');
        }
        $config = (array) config('import_drafts.audio_evidence');
        $mediaType = $body['media_type'] ?? null;
        if (!is_string($mediaType) || !in_array($mediaType, (array) $config['media_types'], true)) {
            throw ImportApiException::validation('That audio type is not accepted.');
        }
        $sha256 = $body['sha256'] ?? null;
        $encoded = $body['data_base64'] ?? null;
        if (!is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1 || !is_string($encoded)) {
            throw ImportApiException::validation('The audio sample needs a SHA-256 and its data.');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            throw ImportApiException::validation('The audio sample data could not be read.');
        }
        if (strlen($bytes) > (int) $config['max_bytes']) {
            throw ImportApiException::validation('The audio sample is too large.');
        }
        if (!hash_equals($sha256, hash('sha256', $bytes))) {
            throw ImportApiException::validation('The audio sample did not match its checksum.');
        }

        return ['provided', $sha256, $bytes];
    }
}
