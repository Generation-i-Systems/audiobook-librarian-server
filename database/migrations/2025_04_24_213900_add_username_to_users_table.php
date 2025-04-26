<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up() {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->nullable()->after('name');
        });
        // Fill in usernames for existing users
        DB::table('users')->whereNull('username')->orWhere('username', '')->get()->each(function ($user) {
            $username = explode('@', $user->email)[0] . '_' . $user->id;
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        });
        // Now set username to not nullable
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable(false)->change();
        });
    }
    public function down() {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }
};
