<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Additive only: a nullable per-user UI language. Null means "follow the browser".
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'locale')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('locale', 10)->nullable();
        });
    }

    public function down(): void
    {
        // Intentionally empty: never drop a column that may hold live user data.
    }
};
