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
        Schema::create('user_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('recipient_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('book_id')->constrained('books')->onDelete('cascade');
            $table->text('message')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_id', 'acknowledged_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_recommendations');
    }
};
