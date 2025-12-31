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
        Schema::create('ai_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('prompt');
            $table->enum('operation_type', ['research', 'list', 'bulk_update']);
            $table->json('generated_queries')->nullable();
            $table->json('results')->nullable();
            $table->json('applied_changes')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->enum('status', ['pending', 'executed', 'applied', 'failed'])->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_queries');
    }
};
