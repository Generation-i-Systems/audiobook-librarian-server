<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Community sharing (phase 1): recommendations to people, groups or everyone on this
     * server, per-recipient inbox state and reactions, a per-user notification list that
     * clients pick up during sync, and per-type notification preferences. Additive only.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('community_family_only')->default(false);
            $table->boolean('community_share_progress')->default(false);
        });

        Schema::table('user_recommendations', function (Blueprint $table): void {
            $table->uuid('batch_id')->nullable()->index();
            $table->string('audience_type', 16)->default('user');
            $table->foreignId('group_id')->nullable()->constrained('groups')->nullOnDelete();
            $table->unsignedBigInteger('start_position_ms')->nullable();
            $table->timestamp('seen_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->string('reaction', 32)->nullable();
            $table->string('reply', 500)->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->index(['sender_id', 'created_at']);
        });

        Schema::create('user_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('delivery', 16)->default('show');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('delivery', 16);
            $table->timestamps();
            $table->unique(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('user_notifications');

        Schema::table('user_recommendations', function (Blueprint $table): void {
            $table->dropIndex(['sender_id', 'created_at']);
            $table->dropConstrainedForeignId('group_id');
            $table->dropIndex(['batch_id']);
            $table->dropColumn([
                'batch_id',
                'audience_type',
                'start_position_ms',
                'seen_at',
                'dismissed_at',
                'reaction',
                'reply',
                'responded_at',
            ]);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['community_family_only', 'community_share_progress']);
        });
    }
};
