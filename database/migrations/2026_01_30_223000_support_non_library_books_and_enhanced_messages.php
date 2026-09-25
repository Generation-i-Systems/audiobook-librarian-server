<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        // 1. Update external_reads
        Schema::table('external_reads', function (Blueprint $table) {
            $table->unsignedBigInteger('book_id')->nullable()->change();
            $table->string('title')->nullable()->after('book_id');
            $table->string('author')->nullable()->after('title');
        });

        // 2. Update listening_statistics
        Schema::table('listening_statistics', function (Blueprint $table) {
            $table->unsignedBigInteger('book_id')->nullable()->change();
            $table->string('title')->nullable()->after('book_id');
            $table->string('author')->nullable()->after('title');
        });

        // 3. Update reading_sessions
        Schema::table('reading_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('book_id')->nullable()->change();
            $table->string('title')->nullable()->after('book_id');
            $table->string('author')->nullable()->after('title');
        });

        // 4. Update user_book_status
        // book_id must become nullable for external books, so it cannot remain
        // part of the primary key. Preserve uniqueness for library-book pairs.
        if (in_array(Schema::getConnection()->getDriverName(), ['pgsql', 'mysql', 'mariadb', 'sqlsrv'], true)) {
            Schema::table('user_book_status', function (Blueprint $table) {
                $table->dropPrimary();
                $table->unique(['user_id', 'book_id'], 'user_book_status_user_book_unique');
            });
        }

        Schema::table('user_book_status', function (Blueprint $table) {
            $table->unsignedBigInteger('book_id')->nullable()->change();
            $table->string('title')->nullable()->after('book_id');
            $table->string('author')->nullable()->after('title');
        });

        // 5. Update messages
        Schema::table('messages', function (Blueprint $table) {
            $table->string('type')->default('general')->after('recipient_id');
            $table->json('payload')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['type', 'payload']);
        });

        Schema::table('user_book_status', function (Blueprint $table) {
            $table->dropColumn(['title', 'author']);
            $table->unsignedBigInteger('book_id')->nullable(false)->change();
        });

        Schema::table('reading_sessions', function (Blueprint $table) {
            $table->dropColumn(['title', 'author']);
            $table->unsignedBigInteger('book_id')->nullable(false)->change();
        });

        Schema::table('listening_statistics', function (Blueprint $table) {
            $table->dropColumn(['title', 'author']);
            $table->unsignedBigInteger('book_id')->nullable(false)->change();
        });

        Schema::table('external_reads', function (Blueprint $table) {
            $table->dropColumn(['title', 'author']);
            $table->unsignedBigInteger('book_id')->nullable(false)->change();
        });
    }
};
