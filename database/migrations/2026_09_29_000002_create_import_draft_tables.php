<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive tables for the imports.v1 draft workflow. Nothing here touches the
 * legacy filesystem import queue or any existing table.
 */
return new class () extends Migration {
    public function up(): void
    {
        Schema::create('import_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 32)->unique();
            $table->unsignedBigInteger('owner_user_id')->index();
            $table->string('state', 32)->index();
            $table->unsignedInteger('revision')->default(1);
            $table->unsignedInteger('plan_revision')->nullable();
            $table->string('source_mode', 32);
            $table->string('source_display_name', 500);
            $table->string('source_root_fingerprint', 512)->nullable();
            $table->json('source_warnings')->nullable();
            $table->unsignedSmallInteger('observation_schema_version');
            $table->json('client_metadata');
            $table->json('analysis_request')->nullable();
            $table->json('recommendation')->nullable();
            $table->json('transfer_summary');
            $table->json('interpretation_error')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['owner_user_id', 'state', 'updated_at']);
            $table->index(['state', 'expires_at']);
            $table->index(['state', 'queued_at']);
        });

        Schema::create('import_draft_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('import_drafts')->cascadeOnDelete();
            $table->string('file_id', 128);
            $table->string('relative_path', 1024);
            $table->string('normalized_path_key', 1024);
            $table->string('role', 32);
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64)->nullable();
            $table->string('fingerprint_algorithm', 64)->nullable();
            $table->string('fingerprint_value', 256)->nullable();
            $table->timestamp('modified_at')->nullable();
            $table->json('media_observation')->nullable();
            $table->string('text_artifact_id', 128)->nullable();
            $table->string('image_artifact_id', 128)->nullable();
            $table->string('transfer_state', 32)->default('not_started')->index();
            $table->unsignedBigInteger('received_bytes')->default(0);
            $table->char('received_sha256', 64)->nullable();
            $table->string('staged_relative_path', 1024)->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'file_id']);
        });

        Schema::create('import_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('import_drafts')->cascadeOnDelete();
            $table->string('artifact_id', 128);
            $table->string('kind', 32);
            $table->string('media_type', 128);
            $table->unsignedBigInteger('bytes')->nullable();
            $table->char('sha256', 64);
            $table->string('storage_key', 512)->nullable();
            $table->longText('inline_text')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'artifact_id']);
        });

        Schema::create('import_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('import_drafts')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->timestamp('approved_at');
            $table->json('metadata');
            $table->string('cover_artifact_id', 128)->nullable();
            $table->json('target');
            $table->string('duplicate_action', 32);
            $table->string('file_operation', 32);
            $table->string('transfer_mode', 32);
            $table->json('manifest_snapshot');
            $table->json('recommendation_snapshot')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->string('invalidated_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'revision']);
        });

        Schema::create('import_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draft_id')->constrained('import_drafts')->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->unsignedInteger('observed_revision');
            $table->json('payload');
            $table->timestamp('created_at')->nullable();

            $table->index(['draft_id', 'id']);
        });

        Schema::create('import_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id');
            $table->string('route_key', 191);
            $table->string('idempotency_key', 64);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->json('response_headers')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['owner_user_id', 'route_key', 'idempotency_key'], 'import_idempotency_scope_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_idempotency_keys');
        Schema::dropIfExists('import_events');
        Schema::dropIfExists('import_plans');
        Schema::dropIfExists('import_artifacts');
        Schema::dropIfExists('import_draft_files');
        Schema::dropIfExists('import_drafts');
    }
};
