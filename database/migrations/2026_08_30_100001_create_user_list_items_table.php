<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('user_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_list_id')->constrained('user_lists')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('added_at');
            $table->unique(['user_list_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_list_items');
    }
};
