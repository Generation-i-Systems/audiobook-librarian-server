<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('book_file_chunk_hashes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->text('file_path');
            $table->string('file_path_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->unsignedBigInteger('file_mtime');
            $table->unsignedInteger('chunk_size');
            $table->string('sha256', 64);
            $table->json('chunks');
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->unique(['book_id', 'file_path_hash', 'chunk_size'], 'book_file_chunk_hashes_unique_file');
            $table->index(['book_id', 'chunk_size']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_file_chunk_hashes');
    }
};
