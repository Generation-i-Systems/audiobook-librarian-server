<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// User-created book lists (e.g. the client's default "Read Queue" plus arbitrary custom
// lists). `string_id` is the client-generated UUID the app uses to reference this list -
// kept separate from the auto-increment `id` so item rows can FK on the cheaper int, while
// the client never needs to learn a server-assigned id (same pattern as `bookmarks.string_id`).
// `created_at`/`updated_at` are stored as epoch-millisecond integers (not Carbon timestamps)
// since the client's SQLDelight-backed source of truth already keys everything on epoch ms.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('string_id');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('created_at');
            $table->unsignedBigInteger('updated_at');
            $table->unique(['user_id', 'string_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_lists');
    }
};
