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
        Schema::create('discovered_books', function (Blueprint $table) {
            $table->id();
            $table->string('abb_id')->unique();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('narrator')->nullable();
            $table->string('category');
            $table->string('url');
            $table->text('description')->nullable();
            $table->string('cover_url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('discovered_at');
            $table->boolean('notified')->default(false);
            $table->timestamps();

            $table->index(['author', 'discovered_at']);
            $table->index(['category', 'discovered_at']);
            $table->index(['notified', 'discovered_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('discovered_books');
    }
};
