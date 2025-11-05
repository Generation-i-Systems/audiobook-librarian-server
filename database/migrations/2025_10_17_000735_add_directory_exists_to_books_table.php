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
            $table->boolean('directory_exists')->default(true)->after('directory_path');
            $table->timestamp('directory_last_checked')->nullable()->after('directory_exists');
            $table->index('directory_exists');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['directory_exists']);
            $table->dropColumn(['directory_exists', 'directory_last_checked']);
        });
    }
};
