<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Introduces a lightweight parent/child account model: a user with parent_user_id
     * set is a managed member of the account rooted at that parent. is_filter_manager
     * designates a non-admin member as authorized to edit system (account-wide) tag
     * filters for their account, alongside the account's parent and full admins.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('parent_user_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
            $table->boolean('is_filter_manager')->default(false)->after('parent_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_user_id');
            $table->dropColumn('is_filter_manager');
        });
    }
};
