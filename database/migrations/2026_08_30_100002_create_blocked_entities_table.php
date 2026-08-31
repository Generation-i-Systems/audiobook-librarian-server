<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One flat per-user list of blocked entities (a book, or every book by an author/series/tag),
// used to hide matching books from the client's browse/search/discovery/library listings.
// `entity_value` is the normalized (lowercase-trimmed) match key the client filters on - a
// book id as a string, or a lowercased author/series/tag name; `entity_ref_id` is the
// matching Book/author/series id when the client knows it, null for a name-only or tag match.
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('blocked_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('string_id');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_ref_id')->nullable();
            $table->string('entity_value');
            $table->string('entity_label');
            $table->unsignedBigInteger('created_at');
            $table->unique(['user_id', 'string_id']);
            $table->unique(['user_id', 'entity_type', 'entity_value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blocked_entities');
    }
};
