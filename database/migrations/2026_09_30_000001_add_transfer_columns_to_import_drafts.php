<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive columns for imports.v1 resumable uploads (phase 5). Only adds nullable
// columns to the draft tables; nothing existing is changed or dropped.
return new class () extends Migration {
    public function up(): void
    {
        Schema::table('import_draft_files', function (Blueprint $table) {
            if (!Schema::hasColumn('import_draft_files', 'expected_sha256')) {
                $table->char('expected_sha256', 64)->nullable()->after('received_sha256');
            }
            if (!Schema::hasColumn('import_draft_files', 'uploaded_at')) {
                $table->timestamp('uploaded_at')->nullable()->after('staged_relative_path');
            }
            if (!Schema::hasColumn('import_draft_files', 'verified_at')) {
                $table->timestamp('verified_at')->nullable()->after('uploaded_at');
            }
        });

        Schema::table('import_drafts', function (Blueprint $table) {
            if (!Schema::hasColumn('import_drafts', 'transfer_verified_plan_revision')) {
                $table->unsignedInteger('transfer_verified_plan_revision')->nullable()->after('plan_revision');
            }
            if (!Schema::hasColumn('import_drafts', 'transfer_verified_at')) {
                $table->timestamp('transfer_verified_at')->nullable()->after('queued_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_drafts', function (Blueprint $table) {
            $table->dropColumn(['transfer_verified_plan_revision', 'transfer_verified_at']);
        });
        Schema::table('import_draft_files', function (Blueprint $table) {
            $table->dropColumn(['expected_sha256', 'uploaded_at', 'verified_at']);
        });
    }
};
