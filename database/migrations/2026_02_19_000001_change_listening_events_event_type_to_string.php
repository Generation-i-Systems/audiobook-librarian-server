<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Change event_type from enum to string to support future event types
     * without requiring migrations (e.g. SPEED_CHANGE, CHAPTER_CHANGE).
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // MySQL requires raw SQL to change enum to varchar
        DB::statement("ALTER TABLE listening_events MODIFY event_type VARCHAR(50) NOT NULL");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE listening_events MODIFY event_type ENUM(
            'PLAY_START', 'PLAY_PAUSE', 'PLAY_RESUME', 'PLAY_STOP',
            'SESSION_START', 'SESSION_END',
            'BOOK_START', 'BOOK_FINISH', 'BOOK_MARK_COMPLETE', 'BOOK_UNMARK_COMPLETE',
            'BOOKMARK_CREATE', 'BOOKMARK_DELETE',
            'SEEK', 'SPEED_CHANGE', 'CHAPTER_CHANGE'
        ) NOT NULL");
    }
};
