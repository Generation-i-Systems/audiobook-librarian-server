<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;

class JobWorkflowService
{
    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array {
        $query = Job::query();

        if ($type) {
            $query->where('type', $type);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($startAfterId) {
            $startJob = Job::find($startAfterId);

            if ($startJob) {
                $query->where('created_at', '<', $startJob->created_at);
            }
        }

        return $query->orderBy($orderBy, $direction)
            ->limit($limit)
            ->get()
            ->toArray();
    }

    public function updateJob(string $jobId, array $data): bool
    {
        $job = Job::findOrFail($jobId);

        return $job->update($data);
    }

    public function clearJobs(bool $confirmed = false): bool
    {
        if (! $confirmed) {
            throw new \RuntimeException('Refusing to clear jobs without explicit destructive-operation confirmation.');
        }

        Job::query()->delete();

        return true;
    }
}
