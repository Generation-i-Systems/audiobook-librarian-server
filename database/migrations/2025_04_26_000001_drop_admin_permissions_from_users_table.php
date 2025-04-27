<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasColumn('users', 'admin_permissions')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('admin_permissions');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasColumn('users', 'admin_permissions')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('admin_permissions')->default(false);
            });
        }
    }
};
