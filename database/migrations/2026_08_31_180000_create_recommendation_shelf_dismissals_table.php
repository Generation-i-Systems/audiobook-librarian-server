<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('recommendation_shelf_dismissals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('shelf_key');
            $table->timestamps();
            $table->unique(['user_id', 'shelf_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendation_shelf_dismissals');
    }
};
