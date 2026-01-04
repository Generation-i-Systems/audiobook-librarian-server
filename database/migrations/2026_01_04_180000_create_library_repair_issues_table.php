<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('library_repair_issues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('book_id')->nullable()->index();
            $table->string('issue_type');
            $table->string('status')->default('pending');
            $table->string('directory_path')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('auto_resolved')->default(false);
            $table->text('resolution_notes')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['issue_type', 'status']);

            $table->foreign('book_id')
                ->references('id')
                ->on('books')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_repair_issues');
    }
};
