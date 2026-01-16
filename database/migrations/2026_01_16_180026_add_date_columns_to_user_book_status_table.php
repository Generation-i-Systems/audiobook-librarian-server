<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('user_book_status', function (Blueprint $table) {
            $table->date('target_date')->nullable()->after('read_count')->comment('Goal date for finishing the book');
            $table->timestamp('started_at')->nullable()->after('target_date')->comment('When the user started reading this book');
            $table->timestamp('finished_at')->nullable()->after('started_at')->comment('When the user finished reading this book');

            // Add index for history and goal queries
            $table->index(['user_id', 'finished_at']);
            $table->index(['user_id', 'target_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_book_status', function (Blueprint $table) {
            // Drop indexes first using the default naming convention
            $table->dropIndex(['user_id', 'finished_at']);
            $table->dropIndex(['user_id', 'target_date']);
            $table->dropColumn(['target_date', 'started_at', 'finished_at']);
        });
    }
};
