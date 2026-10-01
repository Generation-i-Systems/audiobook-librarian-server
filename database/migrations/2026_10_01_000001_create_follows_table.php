<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * The follow/unfollow API (author and series follows) has always written to this
     * table, but its migration was removed with the document-store clean-up, so the
     * endpoints could never succeed. Non-destructive: only creates the table.
     */
    public function up(): void
    {
        if (Schema::hasTable('follows')) {
            return;
        }

        Schema::create('follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('followable_type', 20);
            $table->unsignedBigInteger('followable_id');
            $table->timestamps();

            $table->unique(['user_id', 'followable_type', 'followable_id']);
        });
    }

    public function down(): void
    {
        // Intentionally empty: never drop a table that may hold live user data.
    }
};
