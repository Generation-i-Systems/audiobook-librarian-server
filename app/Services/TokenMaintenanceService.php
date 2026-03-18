<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TokenMaintenanceService
{
    public function createApiToken(array $tokenData): ?string
    {
        try {
            $id = DB::table('api_tokens')->insertGetId($tokenData);

            return (string) $id;
        } catch (\Exception $e) {
            Log::error('MySqlService createApiToken failed: ' . $e->getMessage());

            return null;
        }
    }

    public function getApiTokenByValue(string $tokenValue): ?array
    {
        try {
            $row = DB::table('api_tokens')->where('token', $tokenValue)->first();

            return $row ? (array) $row : null;
        } catch (\Exception $e) {
            Log::error('MySqlService getApiTokenByValue failed: ' . $e->getMessage());

            return null;
        }
    }

    public function deleteApiTokenByValue(string $tokenValue): bool
    {
        try {
            $deleted = DB::table('api_tokens')
                ->where('token', $tokenValue)
                ->delete();

            return $deleted > 0;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteApiTokenByValue failed: ' . $e->getMessage());

            return false;
        }
    }

    public function cleanupOldJobs(int $daysOld): int
    {
        return Job::where('created_at', '<=', now()->subDays($daysOld))
            ->whereIn('status', ['completed', 'failed', 'cancelled'])
            ->delete();
    }
}
