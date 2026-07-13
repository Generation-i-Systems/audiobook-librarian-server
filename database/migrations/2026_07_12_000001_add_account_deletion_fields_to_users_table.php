<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deletion_requested_at')->nullable()->after('remember_token');
            $table->timestamp('deletion_scheduled_for')->nullable()->index()->after('deletion_requested_at');
            $table->string('deletion_cancellation_token_hash', 64)->nullable()->unique()->after('deletion_scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'deletion_requested_at',
                'deletion_scheduled_for',
                'deletion_cancellation_token_hash',
            ]);
        });
    }
};
