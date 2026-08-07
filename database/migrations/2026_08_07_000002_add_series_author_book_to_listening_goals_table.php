<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('listening_goals', function (Blueprint $table): void {
            $table->foreignId('series_id')->nullable()->after('playlist_id')->nullOnDelete()->constrained('series');
            $table->foreignId('author_id')->nullable()->after('series_id')->nullOnDelete()->constrained('authors');
            $table->foreignId('book_id')->nullable()->after('author_id')->nullOnDelete()->constrained('books');
        });
    }

    public function down(): void
    {
        Schema::table('listening_goals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('series_id');
            $table->dropConstrainedForeignId('author_id');
            $table->dropConstrainedForeignId('book_id');
        });
    }
};
