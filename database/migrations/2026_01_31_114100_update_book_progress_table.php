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
        Schema::table('book_progress', function (Blueprint $table) {
            // Make book_id nullable
            $table->unsignedBigInteger('book_id')->nullable()->change();

            // Add client_book_id
            $table->unsignedBigInteger('client_book_id')->nullable()->after('book_id');

            // Add foreign key constraint
            $table->foreign('client_book_id')->references('id')->on('client_books')->onDelete('cascade');

            // Add unique index for client_book_id + device_id
            // Note: The existing unique index is on ['book_id', 'device_id']
            // We should arguably relax the existing unique constraint if book_id can be null,
            // but for now we just add the new one.
            $table->unique(['client_book_id', 'device_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('book_progress', function (Blueprint $table) {
            $table->dropForeign(['client_book_id']);
            $table->dropUnique(['client_book_id', 'device_id']);
            $table->dropColumn('client_book_id');

            // Revert book_id to not null (caution: this might fail if there are nulls)
            // $table->unsignedBigInteger('book_id')->nullable(false)->change();
        });
    }
};
