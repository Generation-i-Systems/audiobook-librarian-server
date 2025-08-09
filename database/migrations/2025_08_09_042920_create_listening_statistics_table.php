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
        Schema::create('listening_statistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('book_id');
            $table->string('user_id')->nullable(); // For future user support
            $table->string('device_id'); // To track different devices
            $table->date('listening_date'); // Date of listening session
            $table->integer('seconds_listened')->default(0); // Duration of this session
            $table->timestamp('session_start')->nullable(); // When session started
            $table->timestamp('session_end')->nullable(); // When session ended
            $table->integer('start_position_seconds')->nullable(); // Position at start of session
            $table->integer('end_position_seconds')->nullable(); // Position at end of session
            $table->string('session_type')->default('listening'); // listening, completed, resumed
            $table->json('metadata')->nullable(); // Additional session data (chapters, speed, etc.)
            $table->timestamps();

            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->index(['device_id', 'listening_date']);
            $table->index(['book_id', 'listening_date']);
            $table->index(['listening_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listening_statistics');
    }
};
