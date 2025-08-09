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
        Schema::create('book_progress', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('book_id');
            $table->string('user_id')->nullable(); // For future user support
            $table->string('device_id'); // To track different devices
            $table->integer('current_position_seconds')->default(0); // Current position in seconds
            $table->integer('total_duration_seconds')->nullable(); // Total book duration
            $table->decimal('progress_percentage', 5, 2)->default(0.00); // 0.00 to 100.00
            $table->integer('current_chapter')->nullable(); // Current chapter number
            $table->string('current_chapter_name')->nullable(); // Current chapter name
            $table->timestamp('last_listened_at')->nullable(); // When last listened
            $table->boolean('completed')->default(false); // If book is finished
            $table->timestamp('completed_at')->nullable(); // When completed
            $table->timestamps();

            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
            $table->unique(['book_id', 'device_id']); // One progress record per book per device
            $table->index(['device_id', 'last_listened_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('book_progress');
    }
};
