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
        Schema::table('client_events', function (Blueprint $table) {
            // Drop the old column and add the new one with correct type and name
            // The old column was 'timestamp' (bigint), but the model and app expect 'event_timestamp' (timestamp/datetime)
            $table->dropColumn('timestamp');
            $table->timestamp('event_timestamp')->after('event_type')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('client_events', function (Blueprint $table) {
            if (Schema::hasColumn('client_events', 'event_timestamp')) {
                $table->dropIndex('client_events_event_timestamp_index');
                $table->dropColumn('event_timestamp');
            }
            if (!Schema::hasColumn('client_events', 'timestamp')) {
                $table->bigInteger('timestamp')->comment('Unix timestamp in milliseconds');
            }
        });
    }
};
