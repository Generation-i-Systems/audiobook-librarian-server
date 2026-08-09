<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Tracks which books have been embedded into the vector store and with
     * what content, so EmbedBookJob can skip re-embedding/re-captioning
     * unchanged books. The vector itself lives in the configured vector
     * store (config('neuron.store')), not in this table.
     */
    public function up(): void
    {
        Schema::create('book_embeddings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('book_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('content_hash');
            $table->string('cover_hash')->nullable();
            $table->text('cover_caption')->nullable();
            $table->timestamp('embedded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_embeddings');
    }
};
