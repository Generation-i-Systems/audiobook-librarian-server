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
        Schema::table('books', function (Blueprint $table) {
            // Add publisher_id as a foreign key
            $table->foreignId('publisher_id')
                ->nullable()
                ->after('series_id')
                ->constrained('publishers')
                ->onDelete('set null');

            // Add an index for better performance on lookups
            $table->index('publisher_id');

            // If there's an existing publisher string column, we'll migrate that data in a separate step
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Drop the foreign key constraint first
            $table->dropForeign(['publisher_id']);

            // Then drop the column
            $table->dropColumn('publisher_id');
        });
    }
};
