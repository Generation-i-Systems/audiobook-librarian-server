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
        Schema::table('books', function (Blueprint $table) {
            $table->boolean('ai_processed')->default(false)->after('needs_review_reasons');
            $table->integer('ai_confidence')->nullable()->after('ai_processed');
            $table->timestamp('ai_processed_at')->nullable()->after('ai_confidence');
            $table->json('ai_suggestions')->nullable()->after('ai_processed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn([
                'ai_processed',
                'ai_confidence',
                'ai_processed_at',
                'ai_suggestions',
            ]);
        });
    }
};
