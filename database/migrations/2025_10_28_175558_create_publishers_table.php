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
        Schema::create('publishers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('website')->nullable();
            $table->string('logo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // First, drop the foreign key constraint on the books table
        Schema::table('books', function (Blueprint $table) {
            if (Schema::hasColumn('books', 'publisher_id')) {
                $table->dropForeign(['publisher_id']);
            }
        });

        // Then drop the publishers table
        Schema::dropIfExists('publishers');
    }
};
