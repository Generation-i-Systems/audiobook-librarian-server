<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Update existing BookProgress records to use user_id 2 if null
        DB::table('book_progress')
            ->whereNull('user_id')
            ->update(['user_id' => 2]);

        // 2. Create ListeningEvent records from existing BookProgress
        $progressRecords = DB::table('book_progress')->get();

        foreach ($progressRecords as $progress) {
            // Check if event already exists for this progress (avoid duplicates if migration runs multiple times or partial data)
            // But since book_progress is a state, not an event, we'll just create a snapshot event.
            // We'll use a deterministic ID based on book_id and updated_at to avoid re-creating on re-runs if possible,
            // or just rely on the fact this is a one-time migration.
            // Actually, let's just create new events.

            $timestamp = $progress->updated_at ? \Carbon\Carbon::parse($progress->updated_at)->timestamp * 1000 : now()->timestamp * 1000;
            $createdAt = $progress->created_at ? \Carbon\Carbon::parse($progress->created_at)->timestamp * 1000 : $timestamp;

            DB::table('listening_events')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $progress->user_id, // Should be populated now
                'book_id' => $progress->book_id,
                'event_type' => $progress->completed ? 'BOOK_FINISH' : 'SESSION_END', // Best guess
                'timestamp_ms' => $timestamp,
                'position_ms' => ($progress->current_position_seconds ?? 0) * 1000,
                'metadata' => json_encode([
                    'migrated' => true,
                    'original_progress_id' => $progress->id,
                    'progress_percentage' => $progress->progress_percentage
                ]),
                'device_id' => $progress->device_id ?? 'unknown_device',
                'timezone' => 'UTC',
                'sync_status' => 'SYNCED',
                'created_at' => $createdAt,
                'synced_at' => now()->timestamp * 1000,
                'migrated_from' => 'book_progress_migration',
                'migration_source_id' => (string) $progress->id,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Remove migrated events
        DB::table('listening_events')
            ->where('migrated_from', 'book_progress_migration')
            ->delete();

        // Note: We cannot revert the user_id update reliably as we don't know which ones were NULL before.
    }
};
