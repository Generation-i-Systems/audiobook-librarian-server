<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('skins', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('author');
            $table->string('version');
            $table->text('description')->nullable();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('forked_from_id')->nullable()->constrained('skins')->onDelete('set null');
            $table->string('file_path');
            $table->string('preview_path')->nullable();
            $table->integer('file_size');
            $table->json('manifest')->nullable();
            $table->boolean('is_public')->default(true);
            $table->integer('download_count')->default(0);
            $table->decimal('average_rating', 3, 2)->default(0.00);
            $table->integer('rating_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_public', 'created_at']);
            $table->index(['is_public', 'download_count']);
            $table->index(['is_public', 'average_rating']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skins');
    }
};
