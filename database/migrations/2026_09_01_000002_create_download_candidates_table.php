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
        Schema::create('download_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->nullable()->constrained('authors')->nullOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->string('title');
            $table->string('abb_url');
            $table->text('magnet_uri')->nullable();
            $table->string('cover_url')->nullable();
            $table->text('description')->nullable();
            $table->string('genre')->nullable();
            $table->string('abb_id')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->timestamp('discovered_at');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('pending_download_id')->nullable()->constrained('pending_downloads')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('author_id');
            $table->index('series_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('download_candidates');
    }
};
