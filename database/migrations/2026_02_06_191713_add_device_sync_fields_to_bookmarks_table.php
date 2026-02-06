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
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->uuid('string_id')->nullable()->after('id');
            $table->string('device_id')->nullable()->after('user_id');
            $table->string('device_name')->nullable()->after('device_id');
            $table->bigInteger('position_ms')->nullable()->after('position');
            $table->integer('chapter_number')->nullable()->after('chapter');
            $table->string('chapter_title')->nullable()->after('chapter_number');
            $table->softDeletes();

            $table->unique(['user_id', 'string_id']);
            $table->index(['device_id']);
            $table->index(['deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookmarks', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'string_id']);
            $table->dropIndex(['device_id']);
            $table->dropIndex(['deleted_at']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'string_id',
                'device_id',
                'device_name',
                'position_ms',
                'chapter_number',
                'chapter_title',
            ]);
        });
    }
};
