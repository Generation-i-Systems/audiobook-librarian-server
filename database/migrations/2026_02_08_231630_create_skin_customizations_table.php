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
        Schema::create('skin_customizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // "color" | "image"
            $table->string('value')->nullable(); // Hex color code
            $table->string('file_path')->nullable(); // if type=image
            $table->string('visibility')->default('private'); // "private" | "public"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skin_customizations');
    }
};
