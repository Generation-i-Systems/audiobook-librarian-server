<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('books', function (Blueprint $table) {
            $table->integer('published_year')->nullable()->after('date_added');
            $table->dropColumn('publication_date');
        });
    }

    public function down()
    {
        Schema::table('books', function (Blueprint $table) {
            $table->date('publication_date')->nullable()->after('date_added');
            $table->dropColumn('published_year');
        });
    }
};
