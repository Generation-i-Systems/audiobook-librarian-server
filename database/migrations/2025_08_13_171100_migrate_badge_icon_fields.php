<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // Move any existing data from deprecated columns into the canonical ones
        if (Schema::hasTable('badges')) {
            // Copy emoji_icon -> icon when icon is null
            if (Schema::hasColumn('badges', 'emoji_icon')) {
                DB::table('badges')
                    ->whereNull('icon')
                    ->whereNotNull('emoji_icon')
                    ->update(['icon' => DB::raw('emoji_icon')]);
            }
            // Copy icon_url -> image_url when image_url is null
            if (Schema::hasColumn('badges', 'icon_url')) {
                DB::table('badges')
                    ->whereNull('image_url')
                    ->whereNotNull('icon_url')
                    ->update(['image_url' => DB::raw('icon_url')]);
            }
        }

        // Drop deprecated columns if they exist
        Schema::table('badges', function (Blueprint $table) {
            if (Schema::hasColumn('badges', 'emoji_icon')) {
                $table->dropColumn('emoji_icon');
            }
            if (Schema::hasColumn('badges', 'icon_url')) {
                $table->dropColumn('icon_url');
            }
        });
    }

    public function down(): void
    {
        // Recreate deprecated columns as nullable and best-effort backfill from canonical fields
        Schema::table('badges', function (Blueprint $table) {
            if (!Schema::hasColumn('badges', 'emoji_icon')) {
                $table->string('emoji_icon', 16)->nullable()->after('description');
            }
            if (!Schema::hasColumn('badges', 'icon_url')) {
                $table->string('icon_url', 255)->nullable()->after('emoji_icon');
            }
        });

        if (Schema::hasTable('badges')) {
            // Copy back from icon -> emoji_icon and image_url -> icon_url only when empty
            DB::table('badges')
                ->whereNull('emoji_icon')
                ->whereNotNull('icon')
                ->update(['emoji_icon' => DB::raw('icon')]);

            DB::table('badges')
                ->whereNull('icon_url')
                ->whereNotNull('image_url')
                ->update(['icon_url' => DB::raw('image_url')]);
        }
    }
};
