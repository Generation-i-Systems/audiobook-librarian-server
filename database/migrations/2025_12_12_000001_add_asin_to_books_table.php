<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('asin')->nullable()->after('isbn');
            $table->index('asin');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['asin']);
            $table->dropColumn('asin');
        });
    }
};
