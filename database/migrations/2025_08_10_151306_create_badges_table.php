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
        Schema::create('badges', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // Unique identifier for the badge
            $table->string('name'); // Display name
            $table->text('description'); // Badge description
            $table->string('icon')->nullable(); // Icon/emoji for the badge
            $table->string('image_url')->nullable(); // URL to badge image
            $table->string('category'); // listening, reading, social, milestone, etc.
            $table->string('tier')->default('bronze'); // bronze, silver, gold, platinum, diamond
            $table->integer('points')->default(0); // Points awarded for earning this badge
            $table->json('criteria'); // JSON criteria for earning the badge
            $table->boolean('is_active')->default(true);
            $table->boolean('is_repeatable')->default(false); // Can be earned multiple times
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'tier']);
            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('badges');
    }
};
