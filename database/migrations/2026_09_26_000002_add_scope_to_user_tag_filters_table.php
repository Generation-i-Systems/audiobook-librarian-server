<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Replaces the single-user locked_by_admin flag with an account-scoped model,
     * mirroring BookTag's scope/owner_key pattern:
     *   - scope=user,   owner_key="user:{user_id}"     — a personal filter the user
     *     themselves may add or remove at will (web or client).
     *   - scope=system, owner_key="account:{rootId}"   — an account-wide filter set by
     *     an admin or a designated account manager; ordinary members cannot change or
     *     remove it. rootId is the account's parent user (users.parent_user_id), or the
     *     user's own id when they are not a managed member of another account.
     *
     * Every user has no parent at migration time, so each existing locked_by_admin row
     * backfills to owner_key="account:{user_id}" — identical in effect to today's
     * single-user lock, just expressed in the account-scoped form.
     *
     * Every step is guarded so this migration can safely re-run to completion after a
     * partial failure (each Schema::table call below is its own statement against a
     * live database, and MySQL DDL is not transactional).
     */
    public function up(): void
    {
        if (!Schema::hasColumn('user_tag_filters', 'scope')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->string('scope', 10)->default('user')->after('mode');
            });
        }

        if (!Schema::hasColumn('user_tag_filters', 'owner_key')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->string('owner_key')->nullable()->after('scope');
            });
        }

        DB::table('user_tag_filters')->where('locked_by_admin', true)->update([
            'scope' => 'system',
            'owner_key' => DB::raw("CONCAT('account:', user_id)"),
        ]);
        DB::table('user_tag_filters')->where('locked_by_admin', false)->update([
            'scope' => 'user',
            'owner_key' => DB::raw("CONCAT('user:', user_id)"),
        ]);

        Schema::table('user_tag_filters', function (Blueprint $table): void {
            $table->string('owner_key')->nullable(false)->change();
        });

        // MySQL requires an index covering the user_id foreign key at all times; add a
        // plain index to carry that duty before dropping the unique index that
        // currently backs it.
        if (!Schema::hasIndex('user_tag_filters', 'user_tag_filters_user_id_index')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->index('user_id');
            });
        }

        if (Schema::hasIndex('user_tag_filters', 'user_tag_filters_user_id_tag_unique')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->dropUnique(['user_id', 'tag']);
            });
        }

        if (!Schema::hasIndex('user_tag_filters', 'user_tag_filters_owner_key_tag_unique')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->unique(['owner_key', 'tag']);
            });
        }

        if (Schema::hasColumn('user_tag_filters', 'locked_by_admin')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->dropColumn('locked_by_admin');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('user_tag_filters', 'locked_by_admin')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->boolean('locked_by_admin')->default(false)->after('mode');
            });
        }

        DB::table('user_tag_filters')->where('scope', 'system')->update(['locked_by_admin' => true]);

        if (Schema::hasIndex('user_tag_filters', 'user_tag_filters_owner_key_tag_unique')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->dropUnique(['owner_key', 'tag']);
            });
        }

        if (!Schema::hasIndex('user_tag_filters', 'user_tag_filters_user_id_tag_unique')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->unique(['user_id', 'tag']);
            });
        }

        if (Schema::hasIndex('user_tag_filters', 'user_tag_filters_user_id_index')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->dropIndex(['user_id']);
            });
        }

        if (Schema::hasColumn('user_tag_filters', 'scope') || Schema::hasColumn('user_tag_filters', 'owner_key')) {
            Schema::table('user_tag_filters', function (Blueprint $table): void {
                $table->dropColumn(['scope', 'owner_key']);
            });
        }
    }
};
