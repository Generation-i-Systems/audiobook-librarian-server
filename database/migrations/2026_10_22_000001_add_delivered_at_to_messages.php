<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Additive only: when a message was first returned to its recipient's client. Messages already
     * acknowledged were necessarily delivered, so they take their acknowledged_at; the rest stay null
     * until a client next fetches them.
     */
    public function up(): void
    {
        if (Schema::hasColumn('messages', 'delivered_at')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            $table->timestamp('delivered_at')->nullable()->after('acknowledged_at');
        });

        DB::table('messages')
            ->whereNotNull('acknowledged_at')
            ->whereNull('delivered_at')
            ->update(['delivered_at' => DB::raw('acknowledged_at')]);
    }

    public function down(): void
    {
        // Intentionally empty: never drop a column that may hold live user data.
    }
};
