<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Remove old series column if it exists
            if (Schema::hasColumn('books', 'series')) {
                $table->dropColumn('series');
            }
            // Add series_id as a foreign key
            $table->foreignId('series_id')->nullable()->after('author_id')->constrained('series')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropForeign(['series_id']);
            $table->dropColumn('series_id');
            $table->string('series')->nullable();
        });
    }
};
