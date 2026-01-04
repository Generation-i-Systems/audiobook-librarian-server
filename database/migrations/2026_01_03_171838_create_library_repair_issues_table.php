<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('library_repair_issues', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('book_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('issue_type');
            $table->string('status')->default('pending');
            $table->string('directory_path')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('auto_resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['issue_type', 'status']);
            $table->index('directory_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_repair_issues');
    }
};
