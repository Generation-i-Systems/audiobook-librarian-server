<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('listening_events', function (Blueprint $table) {
            // Primary identification
            $table->string('id', 255)->primary();  // UUID from client
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('book_id');

            // Event details
            $table->enum('event_type', [
                'PLAY_START', 'PLAY_PAUSE', 'PLAY_RESUME', 'PLAY_STOP',
                'SESSION_START', 'SESSION_END',
                'BOOK_START', 'BOOK_FINISH', 'BOOK_MARK_COMPLETE', 'BOOK_UNMARK_COMPLETE',
                'BOOKMARK_CREATE', 'BOOKMARK_DELETE',
                'SEEK',
            ]);
            $table->unsignedBigInteger('timestamp_ms');  // When event occurred (UTC)
            $table->unsignedBigInteger('position_ms');  // Playback position

            // Metadata (JSON)
            $table->json('metadata')->nullable();  // Type-specific data

            // Device tracking
            $table->string('device_id', 255);  // Device that generated event
            $table->string('timezone', 50);  // User's timezone

            // Sync tracking
            $table->enum('sync_status', ['LOCAL_ONLY', 'PENDING_SYNC', 'SYNCED', 'SYNC_FAILED'])
                ->default('SYNCED');  // Always SYNCED on backend
            $table->unsignedBigInteger('created_at');  // When event was created on client
            $table->unsignedBigInteger('synced_at');  // When event was received by backend

            // Migration tracking
            $table->string('migrated_from', 50)->nullable();  // Source system (if migrated)
            $table->string('migration_source_id', 255)->nullable();  // Original record ID

            // Indexes for performance
            $table->index(['user_id', 'book_id'], 'idx_user_book');
            $table->index(['user_id', 'timestamp_ms'], 'idx_user_timestamp');
            $table->index(['user_id', 'event_type'], 'idx_user_event_type');
            $table->index(['book_id', 'timestamp_ms'], 'idx_book_timestamp');
            $table->index('device_id', 'idx_device');
            $table->index('synced_at', 'idx_synced_at');

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listening_events');
    }
};
