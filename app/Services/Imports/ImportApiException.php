<?php

declare(strict_types=1);

namespace App\Services\Imports;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A user-safe imports.v1 error. Rendered as the contract's error envelope.
 */
class ImportApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
        public readonly bool $retryable = false,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self(404, 'draft_not_found', 'This import could not be found.');
    }

    public static function disabled(): self
    {
        return new self(404, 'imports_disabled', 'Importing from the desktop app is not enabled on this server.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'import_not_permitted', 'Your account is not allowed to import books.');
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function validation(string $message, array $details = []): self
    {
        return new self(422, 'validation_failed', $message, $details);
    }

    /**
     * A request that is well-formed but violates import policy (unknown target, unoffered option, ...).
     *
     * @param array<string, mixed> $details
     */
    public static function policy(string $code, string $message, array $details = []): self
    {
        return new self(422, $code, $message, $details);
    }

    public static function revisionRequired(): self
    {
        return new self(
            428,
            'revision_required',
            'Send the draft revision you reviewed in the If-Match header.'
        );
    }

    /**
     * @param array<string, mixed> $currentDraft
     */
    public static function revisionConflict(int $currentRevision, array $currentDraft): self
    {
        return new self(
            409,
            'draft_revision_conflict',
            'This import changed while it was open.',
            ['current_revision' => $currentRevision, 'draft' => $currentDraft],
            true
        );
    }

    public static function invalidState(string $state, string $action): self
    {
        return new self(
            409,
            'invalid_state_transition',
            'This import can no longer be ' . $action . '.',
            ['state' => $state]
        );
    }

    public function render(): JsonResponse
    {
        $error = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'retryable' => $this->retryable,
        ];
        if ($this->details !== []) {
            $error['details'] = $this->details;
        }

        return response()->json([
            'contract_version' => config('import_drafts.contract_version'),
            'error' => $error,
        ], $this->status);
    }
}
