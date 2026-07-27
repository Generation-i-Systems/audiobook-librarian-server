<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('chapters', function (Blueprint $table): void {
            $table->decimal('start_seconds', 12, 3)->nullable()->after('reader');
            $table->string('source')->nullable()->after('listen_url');
        });
    }

    public function down(): void
    {
        Schema::table('chapters', function (Blueprint $table): void {
            $table->dropColumn(['start_seconds', 'source']);
        });
    }
};
