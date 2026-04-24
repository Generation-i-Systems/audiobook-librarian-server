<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->string('librivox_id')->nullable()->unique()->after('asin');
            $table->json('librivox_info')->nullable()->after('hardcover_info');
        });

        Schema::table('chapters', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('chapter_number');
            $table->string('reader')->nullable()->after('title');
            $table->string('listen_url')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table): void {
            $table->dropUnique(['librivox_id']);
            $table->dropColumn(['librivox_id', 'librivox_info']);
        });

        Schema::table('chapters', function (Blueprint $table): void {
            $table->dropColumn(['title', 'reader', 'listen_url']);
        });
    }
};
