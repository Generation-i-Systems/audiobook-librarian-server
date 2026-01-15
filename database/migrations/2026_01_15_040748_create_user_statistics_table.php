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
        Schema::create('user_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('statistic_key')->comment('e.g., books_completed, total_listening_time');
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'statistic_key']);
            $table->index('statistic_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_statistics');
    }
};
