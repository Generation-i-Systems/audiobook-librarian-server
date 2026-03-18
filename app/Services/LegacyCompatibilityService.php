<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Book;
use App\Models\ExternalRead;
use App\Models\Job;
use App\Models\ListeningStatistic;
use App\Models\Message;
use App\Models\ReadingSession;
use App\Models\UserBookStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LegacyCompatibilityService
{
    public function updateJobStatus(string $jobId, string $type, string $status, array $metadata = []): bool
    {
        $job = $this->findLegacyJob($jobId, $type) ?? new Job();

        $payload = is_array($job->payload ?? null) ? $job->payload : [];
        $payload = array_merge($payload, $metadata, ['job_id' => $jobId]);

        $job->type = $type;
        $job->status = $status;
        $job->payload = $payload;

        return $job->save();
    }

    public function jobExistsByDirectoryPath(string $directoryPath): bool
    {
        return Job::query()->get()->contains(function (Job $job) use ($directoryPath): bool {
            $payload = is_array($job->payload ?? null) ? $job->payload : [];
            $candidates = [
                $payload['directory_path'] ?? null,
                $payload['directoryPath'] ?? null,
                $payload['directory'] ?? null,
            ];

            return in_array($directoryPath, $candidates, true);
        });
    }

    public function followExists(string $userId, string $followableType, string $followableId): bool
    {
        if (!Schema::hasTable('follows')) {
            return false;
        }

        return DB::table('follows')
            ->where('user_id', $userId)
            ->where('followable_type', $followableType)
            ->where('followable_id', $followableId)
            ->exists();
    }

    public function getQueueCollection(mixed $name): mixed
    {
        return null;
    }

    public function getClient(): mixed
    {
        return null;
    }

    public function linkNonLibraryBooks(): int
    {
        $linkedCount = 0;
        $models = [
            ExternalRead::class,
            ListeningStatistic::class,
            ReadingSession::class,
            UserBookStatus::class,
        ];

        foreach ($models as $modelClass) {
            $records = $modelClass::whereNull('book_id')
                ->whereNotNull('title')
                ->whereNotNull('author')
                ->get();

            foreach ($records as $record) {
                $book = Book::where('title', $record->title)
                    ->whereHas('authors', function ($query) use ($record): void {
                        $query->where('name', 'like', '%' . $record->author . '%');
                    })
                    ->first();

                if (!$book) {
                    continue;
                }

                $record->book_id = $book->id;
                $record->save();
                $linkedCount++;

                $recipientId = null;
                if (isset($record->user_id)) {
                    $recipientId = (int) $record->user_id;
                } elseif (isset($record->deviceId)) {
                    $recipientId = (int) $record->deviceId;
                }

                if (!$recipientId) {
                    continue;
                }

                Message::create([
                    'sender_id' => null,
                    'recipient_id' => $recipientId,
                    'type' => 'book_linked',
                    'content' => "Your statistical data for '{$record->title}' has been linked to '{$book->title}' in the library.",
                    'payload' => [
                        'book_id' => $book->id,
                        'title' => $book->title,
                        'original_title' => $record->title,
                        'original_author' => $record->author,
                    ],
                ]);
            }
        }

        return $linkedCount;
    }

    private function findLegacyJob(string $jobId, string $type): ?Job
    {
        if (ctype_digit($jobId)) {
            return Job::find($jobId);
        }

        return Job::query()
            ->where('type', $type)
            ->get()
            ->first(function (Job $job) use ($jobId): bool {
                $payload = is_array($job->payload ?? null) ? $job->payload : [];

                return ($payload['job_id'] ?? null) === $jobId;
            });
    }
}
