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
            // Deliberately independent of `status`/`finished_at`/`read_count`: marking a book
            // "read" here must never affect completion stats, badges, or goal fulfillment,
            // which are all keyed off `status = 'completed'` (see BookCompletionService).
            $table->timestamp('marked_read_at')->nullable()->after('finished_at')
                ->comment('When the user manually marked this book as read; independent of status/finished_at, never counted toward stats or goals');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_book_status', function (Blueprint $table) {
            $table->dropColumn('marked_read_at');
        });
    }
};
