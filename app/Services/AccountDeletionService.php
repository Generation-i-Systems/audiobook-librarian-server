<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountDeletionService
{
    public const RETENTION_DAYS = 30;

    public function schedule(User $user): string
    {
        $cancellationToken = Str::random(64);

        DB::transaction(function () use ($user, $cancellationToken): void {
            $user->forceFill([
                'deletion_requested_at' => now(),
                'deletion_scheduled_for' => now()->addDays(self::RETENTION_DAYS),
                'deletion_cancellation_token_hash' => hash('sha256', $cancellationToken),
            ])->save();

            $user->tokens()->delete();
            DB::table('api_tokens')->where('user_id', $user->id)->delete();
            $user->delete();
        });

        return $cancellationToken;
    }

    public function cancel(string $cancellationToken): bool
    {
        return DB::transaction(function () use ($cancellationToken): bool {
            $user = User::onlyTrashed()
                ->where('deletion_cancellation_token_hash', hash('sha256', $cancellationToken))
                ->lockForUpdate()
                ->first();

            if ($user === null || $user->deletion_scheduled_for === null || $user->deletion_scheduled_for->isPast()) {
                return false;
            }

            $user->restore();
            $user->forceFill([
                'deletion_requested_at' => null,
                'deletion_scheduled_for' => null,
                'deletion_cancellation_token_hash' => null,
            ])->save();

            return true;
        });
    }

    public function purgeDueAccounts(): int
    {
        $userIds = User::onlyTrashed()
            ->whereNotNull('deletion_scheduled_for')
            ->where('deletion_scheduled_for', '<=', now())
            ->pluck('id');

        foreach ($userIds as $userId) {
            app(UserAccountService::class)->permanentlyDeleteUser((string) $userId);
        }

        return $userIds->count();
    }
}
