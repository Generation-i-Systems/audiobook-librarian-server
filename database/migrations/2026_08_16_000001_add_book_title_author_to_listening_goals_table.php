<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Fallback book identity for book_hours/book_completion goals when the client has no
     * numeric book_id (e.g. a local-only book not yet matched to the catalog) - mirrors the
     * title/author columns already added to listening_statistics and user_book_status for the
     * same reason.
     */
    public function up(): void
    {
        Schema::table('listening_goals', function (Blueprint $table) {
            $table->string('book_title')->nullable()->after('book_id');
            $table->string('book_author')->nullable()->after('book_title');
        });
    }

    public function down(): void
    {
        Schema::table('listening_goals', function (Blueprint $table) {
            $table->dropColumn(['book_title', 'book_author']);
        });
    }
};
