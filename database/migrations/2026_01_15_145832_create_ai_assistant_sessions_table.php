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
        Schema::create('ai_assistant_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('conversation_history')->nullable();
            $table->string('last_intent')->nullable();
            $table->json('operations')->nullable();
            $table->string('status')->default('pending_approval'); // pending_approval, approved, completed, partially_completed, cancelled
            $table->timestamp('executed_at')->nullable();
            $table->json('execution_results')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_assistant_sessions');
    }
};
