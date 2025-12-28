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
        Schema::create('favorite_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('author_name');
            $table->boolean('notify_email')->default(true);
            $table->timestamp('last_notification_sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'author_name']);
            $table->index('author_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('favorite_authors');
    }
};
