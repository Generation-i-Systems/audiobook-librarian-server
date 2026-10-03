<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive: one nullable JSON column holding the audio-sample requests the server made for a draft.
// Nothing existing is changed or dropped.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('import_drafts', function (Blueprint $table) {
            if (!Schema::hasColumn('import_drafts', 'evidence_requests')) {
                $table->json('evidence_requests')->nullable()->after('interpretation_error');
            }
        });
    }

    public function down(): void
    {
        // Intentionally not destructive: the column is left in place.
    }
};
