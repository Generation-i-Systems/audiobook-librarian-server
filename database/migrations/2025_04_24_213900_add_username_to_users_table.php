<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        // Add the username column if it doesn't exist
        if (!Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username')->unique()->nullable()->after('name');
            });
        }
        // Fill in usernames for existing users if the column now exists
        if (Schema::hasColumn('users', 'username')) {
            DB::table('users')->whereNull('username')->orWhere('username', '')->get()->each(function ($user) {
                $username = explode('@', $user->email)[0] . '_' . $user->id;
                DB::table('users')->where('id', $user->id)->update(['username' => $username]);
            });
            // Now set username to not nullable (if not already)
            Schema::table('users', function (Blueprint $table) {
                $table->string('username')->nullable(false)->change();
            });
        }
    }
    public function down() {
        // Drop the username column if it exists
        if (Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('username');
            });
        }
    }
};
