<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add the column as nullable first
        Schema::table('narrators', function (Blueprint $table) {
            $table->string('normalized_name')->after('name')->nullable();
        });

        // Use raw SQL to update existing records with normalized names
        // This is more efficient than loading all models
        $narrators = DB::table('narrators')->get();

        foreach ($narrators as $narrator) {
            $normalizedName = \App\Models\Narrator::normalizeName($narrator->name);
            DB::table('narrators')
                ->where('id', $narrator->id)
                ->update(['normalized_name' => $normalizedName]);
        }

        // Now make the column not nullable and add an index
        // Using raw SQL for better compatibility
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        if ($driver === 'sqlite') {
            // SQLite needs special handling for modifying columns
            // Create a new table with the correct schema
            DB::statement('CREATE TABLE narrators_temp (id INTEGER PRIMARY KEY AUTOINCREMENT, ' .
                'name VARCHAR(255) NOT NULL, normalized_name VARCHAR(255) NOT NULL, ' .
                'created_at TIMESTAMP, updated_at TIMESTAMP)');

            DB::statement('INSERT INTO narrators_temp (id, name, normalized_name, created_at, updated_at) ' .
                'SELECT id, name, "", created_at, updated_at FROM narrators');

            // Drop the old table and rename the new one
            Schema::drop('narrators');
            DB::statement('ALTER TABLE narrators_temp RENAME TO narrators');

            // Recreate the index
            DB::statement('CREATE INDEX narrators_normalized_name_index ON narrators (normalized_name)');
        } else {
            // For other databases, use the schema builder
            Schema::table('narrators', function (Blueprint $table) {
                $table->string('normalized_name')->nullable(false)->change();
                $table->index('normalized_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('narrators', function (Blueprint $table) {
            $table->dropIndex(['normalized_name']);
            $table->dropColumn('normalized_name');
        });
    }
};
