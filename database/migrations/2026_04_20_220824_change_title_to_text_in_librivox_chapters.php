<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('librivox_chapters', function (Blueprint $table): void {
            $table->text('title')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('librivox_chapters', function (Blueprint $table): void {
            $table->string('title')->nullable()->change();
        });
    }
};
