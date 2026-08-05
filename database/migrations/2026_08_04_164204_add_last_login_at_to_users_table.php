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
        if (!Schema::hasColumn('users', 'last_login_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('last_login_at')->nullable()->after('email_verified_at');
            });
        }

        if (!$this->indexExists('users', 'idx_users_last_login')) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('last_login_at', 'idx_users_last_login');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_last_login');
            $table->dropColumn('last_login_at');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = $connection->select("PRAGMA index_list({$table})");
            foreach ($indexes as $index) {
                if ($index->name === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $indexes = $connection->select(
            'SHOW INDEX FROM ' . $table . ' WHERE Key_name = ?',
            [$indexName]
        );

        return count($indexes) > 0;
    }
};
