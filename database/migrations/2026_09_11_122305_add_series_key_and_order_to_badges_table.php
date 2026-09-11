<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (!Schema::hasColumn('badges', 'series_key')) {
                // Badges that measure the same underlying quantity at increasing thresholds
                // (e.g. "books_completed": 1, 5, 10, 25...) share a series_key so the client can
                // link "next tier" without guessing from the badge name/category, which have
                // repeatedly produced incorrect groupings (badges that merely share a category
                // or a bronze/silver/gold/... tier label are not necessarily the same series).
                $table->string('series_key', 64)->nullable()->after('tier');
            }
            if (!Schema::hasColumn('badges', 'series_order')) {
                $table->unsignedInteger('series_order')->nullable()->after('series_key');
            }
            $table->index(['series_key', 'series_order']);
        });
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            $table->dropIndex(['series_key', 'series_order']);
            if (Schema::hasColumn('badges', 'series_order')) {
                $table->dropColumn('series_order');
            }
            if (Schema::hasColumn('badges', 'series_key')) {
                $table->dropColumn('series_key');
            }
        });
    }
};
