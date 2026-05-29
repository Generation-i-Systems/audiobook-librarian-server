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
        // Safety: Disable foreign key checks to handle renames and constraint drops safely
        Schema::disableForeignKeyConstraints();

        Schema::table('user_book_queues', function (Blueprint $table) {
            // 1. Drop foreign keys referencing this table
            $table->dropForeign(['user_id']);
            $table->dropForeign(['book_id']);

            // 2. Drop old composite primary key (user_id, order)
            $table->dropPrimary(['user_id', 'order']);
        });

        // 3. Rename table
        Schema::rename('user_book_queues', 'user_book_status');

        Schema::table('user_book_status', function (Blueprint $table) {
            // 4. Add new columns
            $table->string('status', 20)->default('queue')->after('book_id');
            $table->json('status_detail')->nullable()->after('status');
            $table->unsignedSmallInteger('read_count')->default(0)->after('status_detail');

            // 5. Update index and key structure
            // New primary key: (user_id, book_id) - A user can only have one status per book.
            $table->primary(['user_id', 'book_id']);

            // Re-add foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');

            // Index for efficient status lookups
            $table->index(['user_id', 'status']);
        });

        // Safety: Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // We will perform a partial, safe rollback, leaving the table under the old name
        Schema::disableForeignKeyConstraints();

        Schema::table('user_book_status', function (Blueprint $table) {
            // Reverse 5: Drop new primary key, indexes, and foreign keys
            $table->dropPrimary(['user_id', 'book_id']);
            $table->dropIndex('user_book_status_user_id_status_index');
            $table->dropForeign(['user_id']);
            $table->dropForeign(['book_id']);

            // Reverse 4: Drop new columns
            // Non-destructive operation is the default, but dropping columns is destructive.
            // We proceed with the assumption that a rollback is safe in this context.
            $table->dropColumn(['status', 'status_detail', 'read_count']);

            // Reverse 2: Re-add old primary key
            $table->primary(['user_id', 'order']);
        });

        // Reverse 3: Rename table back
        Schema::rename('user_book_status', 'user_book_queues');

        // Re-add foreign keys to the original table
        Schema::table('user_book_queues', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('book_id')->references('id')->on('books')->onDelete('cascade');
        });

        Schema::enableForeignKeyConstraints();
    }
};
