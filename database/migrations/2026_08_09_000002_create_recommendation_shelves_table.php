<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Precomputed, cached "discovery shelf" rows for the Netflix-style Browse page.
     * Each shelf is produced by a RecommendationStrategyInterface implementation and
     * fully replaced on each RecommendationEngine::recompute() run for that user —
     * never queried live per request (the file vector store is O(n) per lookup).
     */
    public function up(): void
    {
        Schema::create('recommendation_shelves', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('shelf_key');
            $table->string('title');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'shelf_key']);
        });

        Schema::create('recommendation_shelf_books', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shelf_id')->constrained('recommendation_shelves')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rank')->default(0);
            $table->float('score')->nullable();
            $table->timestamps();

            $table->unique(['shelf_id', 'book_id']);
            $table->index(['shelf_id', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_shelf_books');
        Schema::dropIfExists('recommendation_shelves');
    }
};
