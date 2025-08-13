<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (!Schema::hasColumn('badges', 'emoji_icon')) {
                $table->string('emoji_icon', 16)->nullable()->after('description');
            }
            if (!Schema::hasColumn('badges', 'icon_url')) {
                $table->string('icon_url', 255)->nullable()->after('emoji_icon');
            }
        });
    }

    public function down(): void
    {
        Schema::table('badges', function (Blueprint $table) {
            if (Schema::hasColumn('badges', 'icon_url')) {
                $table->dropColumn('icon_url');
            }
            if (Schema::hasColumn('badges', 'emoji_icon')) {
                $table->dropColumn('emoji_icon');
            }
        });
    }
};
