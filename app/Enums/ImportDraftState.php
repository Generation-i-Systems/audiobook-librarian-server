<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportDraftState: string
{
    case CREATED = 'created';
    case INTERPRETING = 'interpreting';
    case AWAITING_REVIEW = 'awaiting_review';
    case APPROVED = 'approved';
    case TRANSFERRING = 'transferring';
    case VERIFYING = 'verifying';
    case QUEUED = 'queued';
    case IMPORTING = 'importing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case NEEDS_ATTENTION = 'needs_attention';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function isCancellable(): bool
    {
        return in_array($this, [
            self::CREATED,
            self::INTERPRETING,
            self::AWAITING_REVIEW,
            self::APPROVED,
            self::TRANSFERRING,
            self::VERIFYING,
        ], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::EXPIRED, self::FAILED], true);
    }
}
