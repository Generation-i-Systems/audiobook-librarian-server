<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Job;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WorkflowMessagingService
{
    private ?JobWorkflowService $jobWorkflowService = null;

    private function getJobWorkflowService(): JobWorkflowService
    {
        return $this->jobWorkflowService ??= app(JobWorkflowService::class);
    }

    public function getJob(string $jobId): ?array
    {
        $job = Job::find($jobId);

        return $job ? $job->toArray() : null;
    }

    public function getJobs(): array
    {
        return Job::query()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Job $job): array {
                return [
                    'id' => (string) $job->id,
                    'type' => (string) $job->type,
                    'status' => (string) $job->status,
                    'data' => $job->payload,
                    'startedAt' => $job->created_at ? $job->created_at->toIso8601String() : null,
                ];
            })
            ->toArray();
    }

    public function getJobCount(): int
    {
        return Job::count();
    }

    public function createJob(array $data): bool
    {
        try {
            Job::create([
                'type' => $data['type'] ?? 'generic',
                'status' => $data['status'] ?? 'pending',
                'payload' => $data,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService createJob failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteJob(string $jobId): bool
    {
        try {
            $job = Job::where('id', $jobId)->first();

            if (!$job) {
                return false;
            }

            $job->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteJob failed: ' . $e->getMessage());

            return false;
        }
    }

    public function getMessages(?string $userId = null, bool $includeAcknowledged = false, int $limit = 100): array
    {
        $query = Message::query();

        if ($userId) {
            $query->where('recipient_id', $userId);
        }

        if (!$includeAcknowledged) {
            $query->whereNull('acknowledged_at');
        }

        return $query->with('sender')
            ->limit($limit)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    public function getUsersForMessaging(): array
    {
        return User::all()->toArray();
    }

    public function createMessage(array $messageData): ?string
    {
        try {
            $message = Message::create([
                'sender_id' => $messageData['sender_id'] ?? null,
                'recipient_id' => $messageData['recipient_id'],
                'content' => $messageData['content'],
                'type' => $messageData['type'] ?? 'general',
                'payload' => $messageData['payload'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return (string) $message->id;
        } catch (\Exception $e) {
            Log::error('MySqlService createMessage failed: ' . $e->getMessage());

            return null;
        }
    }

    public function acknowledgeMessage(string $messageId): bool
    {
        try {
            $message = Message::find($messageId);

            if (!$message) {
                return false;
            }

            $message->acknowledged_at = now();

            return $message->save();
        } catch (\Exception $e) {
            Log::error('MySqlService acknowledgeMessage failed: ' . $e->getMessage());

            return false;
        }
    }

    public function createFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            return DB::table('follows')->insert([
                'user_id' => $userId,
                'followable_type' => $followableType,
                'followable_id' => $followableId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Exception $e) {
            Log::error('MySqlService createFollow failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteFollow(string $userId, string $followableType, string $followableId): bool
    {
        try {
            return DB::table('follows')
                ->where('user_id', $userId)
                ->where('followable_type', $followableType)
                ->where('followable_id', $followableId)
                ->delete() > 0;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteFollow failed: ' . $e->getMessage());

            return false;
        }
    }

    public function listJobs(
        ?string $type = null,
        ?string $status = null,
        int $limit = 50,
        string $orderBy = 'updated_at',
        string $direction = 'DESC',
        ?string $startAfterId = null
    ): array {
        return $this->getJobWorkflowService()->listJobs($type, $status, $limit, $orderBy, $direction, $startAfterId);
    }

    public function updateJob(string $jobId, array $data): bool
    {
        return $this->getJobWorkflowService()->updateJob($jobId, $data);
    }

    public function clearJobs(bool $confirmed = false): bool
    {
        return $this->getJobWorkflowService()->clearJobs($confirmed);
    }
}
