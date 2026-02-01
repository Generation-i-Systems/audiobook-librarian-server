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
        Schema::table('listening_statistics', function (Blueprint $table) {
            $table->bigInteger('actual_duration_ms')->nullable()->default(0)->after('metadata');
            $table->json('events')->nullable()->after('actual_duration_ms');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listening_statistics', function (Blueprint $table) {
            $table->dropColumn('events');
            $table->dropColumn('actual_duration_ms');
        });
    }
};
