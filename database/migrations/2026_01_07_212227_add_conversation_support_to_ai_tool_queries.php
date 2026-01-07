<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('ai_tool_queries', function (Blueprint $table) {
            $table->string('conversation_id', 36)->nullable()->after('user_id');
            $table->foreignId('parent_query_id')->nullable()->after('conversation_id')->constrained('ai_tool_queries')->onDelete('cascade');
            $table->integer('query_sequence')->default(1)->after('parent_query_id');

            $table->index('conversation_id');
            $table->index('parent_query_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_tool_queries', function (Blueprint $table) {
            $table->dropForeign(['parent_query_id']);
            $table->dropIndex(['conversation_id']);
            $table->dropIndex(['parent_query_id']);
            $table->dropColumn(['conversation_id', 'parent_query_id', 'query_sequence']);
        });
    }
};
