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
        Schema::create('user_badges', function (Blueprint $table) {
            $table->id();
            $table->string('user_id'); // Can be auth user ID or device ID for anonymous users
            $table->string('device_id')->nullable(); // Device identifier for cross-device tracking
            $table->foreignId('badge_id')->constrained()->onDelete('cascade');
            $table->timestamp('earned_at');
            $table->json('criteria_met')->nullable(); // Store the specific criteria values when earned
            $table->integer('progress_value')->nullable(); // Current progress towards badge (for tracking)
            $table->boolean('is_notified')->default(false); // Whether user has been notified of earning this
            $table->integer('tier_level')->default(1); // For repeatable badges (e.g., 1st, 2nd, 3rd time earned)
            $table->timestamps();

            $table->index(['user_id', 'badge_id']);
            $table->index(['device_id', 'badge_id']);
            $table->index(['earned_at']);
            $table->index(['is_notified']);

            // Unique constraint for non-repeatable badges per user
            $table->unique(['user_id', 'badge_id', 'tier_level'], 'unique_user_badge_tier');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_badges');
    }
};
