<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('skin_ratings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('skin_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['skin_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skin_ratings');
    }
};
