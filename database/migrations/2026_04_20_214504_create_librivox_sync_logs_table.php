<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('librivox_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('language', 50);
            $table->enum('sync_type', ['full', 'delta']);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->integer('since_timestamp')->nullable();
            $table->unsignedInteger('books_imported')->default(0);
            $table->unsignedInteger('books_failed')->default(0);
            $table->string('status', 20)->default('running');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['language', 'status', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('librivox_sync_logs');
    }
};
