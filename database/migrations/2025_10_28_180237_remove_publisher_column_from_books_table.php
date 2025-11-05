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
            // Drop the old publisher column as we've migrated the data to the publishers table
            if (Schema::hasColumn('books', 'publisher')) {
                $table->dropColumn('publisher');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // Add the publisher column back if rolling back
            if (!Schema::hasColumn('books', 'publisher')) {
                $table->string('publisher')->nullable()->after('series_id');

                // Note: We can't automatically restore the publisher data here
                // as we don't have a way to map publisher_id back to the publisher name
                // without additional logic. This would need to be handled separately
                // if a rollback is needed.
            }
        });
    }
};
