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
        Schema::table('book_genre', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('genre_id');
            $table->index(['book_id', 'is_primary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_genre', function (Blueprint $table) {
            $table->dropIndex(['book_id', 'is_primary']);
            $table->dropColumn('is_primary');
        });
    }
};
