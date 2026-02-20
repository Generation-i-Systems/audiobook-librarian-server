<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class () extends Migration {
    public function up(): void
    {
        $progressRecords = DB::table('book_progress')
            ->select([
                'book_progress.id',
                'book_progress.book_id',
                'book_progress.user_id',
                'book_progress.device_id',
                'book_progress.current_position_seconds',
                'book_progress.progress_percentage',
                'book_progress.current_chapter',
                'book_progress.current_chapter_name',
                'book_progress.completed',
                'book_progress.updated_at',
            ])
            ->whereNotNull('book_progress.device_id')
            ->whereNotNull('book_progress.user_id')
            ->get();

        $now = now();

        foreach ($progressRecords as $record) {
            $updatedAtMs = $record->updated_at
                ? (int) (\Carbon\Carbon::parse($record->updated_at)->timestamp * 1000)
                : (int) ($now->timestamp * 1000);

            try {
                DB::table('book_positions')->insertOrIgnore([
                    'user_id' => $record->user_id,
                    'book_id' => $record->book_id,
                    'device_id' => $record->device_id,
                    'position_ms' => $record->current_position_seconds * 1000,
                    'progress_percentage' => $record->progress_percentage ?? 0,
                    'current_chapter' => $record->current_chapter,
                    'current_chapter_name' => $record->current_chapter_name,
                    'completed' => $record->completed ?? false,
                    'last_event_timestamp_ms' => $updatedAtMs,
                    'last_event_id' => "migrated-book_progress-{$record->id}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\Exception $e) {
                Log::warning('Failed to migrate book_progress record', [
                    'id' => $record->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info("Migrated {$progressRecords->count()} book_progress records to book_positions");
    }

    public function down(): void
    {
        DB::table('book_positions')
            ->where('last_event_id', 'LIKE', 'migrated-book_progress-%')
            ->delete();
    }
};
