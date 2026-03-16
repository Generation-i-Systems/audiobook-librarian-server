<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('listening_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period_type'); // day, week, month, year
            $table->string('metric');      // total_hours, genre_hours, playlist_hours, fiction_hours, nonfiction_hours
            $table->integer('target_minutes');
            $table->foreignId('genre_id')->nullable()->nullOnDelete()->constrained('genres');
            $table->foreignId('playlist_id')->nullable()->nullOnDelete()->constrained('playlists');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['user_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listening_goals');
    }
};
