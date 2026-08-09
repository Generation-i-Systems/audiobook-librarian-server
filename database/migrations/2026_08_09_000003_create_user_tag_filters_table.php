<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Per-user content filter: require or ban a tag from every book listing/search/
     * discovery-shelf surface (injected in MySqlService::listBooks()). A row can be
     * set by the user themselves, or by an admin with locked_by_admin=true, which the
     * owning user cannot remove or overwrite (see UserTagFilterController vs.
     * AdminUserTagFilterController).
     */
    public function up(): void
    {
        Schema::create('user_tag_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tag');
            $table->string('mode', 10); // 'require' | 'ban'
            $table->boolean('locked_by_admin')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'tag']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tag_filters');
    }
};
