<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('user_book_status', function (Blueprint $table) {
            $table->foreignId('playlist_id')->nullable()->nullOnDelete()->constrained('playlists')->after('user_id');
            $table->index(['user_id', 'playlist_id']);
        });
    }

    public function down(): void
    {
        Schema::table('user_book_status', function (Blueprint $table) {
            $table->dropForeign(['playlist_id']);
            $table->dropIndex(['user_id', 'playlist_id']);
            $table->dropColumn('playlist_id');
        });
    }
};
