<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Generalizes book_tags from "one private tag list per (user, book)" to
     * three visibility tiers: system (admin-only, everyone sees it), group
     * (group-members-only), and user (private, unchanged from before).
     * owner_key identifies which of those three a row belongs to
     * ("system" | "group:{id}" | "user:{id}") so uniqueness can be enforced
     * per (book_id, owner_key) without relying on nullable-column semantics.
     */
    public function up(): void
    {
        Schema::table('book_tags', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'book_id']);
        });

        Schema::table('book_tags', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('scope', 20)->default('user')->after('book_id');
            $table->foreignId('group_id')->nullable()->after('scope')->constrained()->cascadeOnDelete();
            $table->string('owner_key')->after('group_id');
        });

        // Backfill in PHP rather than raw SQL string concatenation, which
        // isn't portable across the sqlite (tests) / mysql (production) drivers.
        DB::table('book_tags')->select('id', 'user_id')->orderBy('id')->each(function (object $row): void {
            DB::table('book_tags')->where('id', $row->id)->update([
                'owner_key' => 'user:' . $row->user_id,
            ]);
        });

        Schema::table('book_tags', function (Blueprint $table): void {
            $table->unique(['book_id', 'owner_key']);
            $table->index(['book_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::table('book_tags', function (Blueprint $table): void {
            $table->dropUnique(['book_id', 'owner_key']);
            $table->dropIndex(['book_id', 'scope']);
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['scope', 'owner_key']);
        });

        Schema::table('book_tags', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
            $table->unique(['user_id', 'book_id']);
        });
    }
};
