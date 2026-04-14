<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            $table->string('emoji', 16)->nullable()->after('name');
            $table->string('icon_path')->nullable()->after('emoji');
        });

        foreach ($this->genreVisuals() as $name => $visuals) {
            DB::table('genres')
                ->where('name', $name)
                ->update([
                    'emoji' => $visuals['emoji'],
                    'icon_path' => $visuals['icon_path'],
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('genres', function (Blueprint $table) {
            $table->dropColumn(['emoji', 'icon_path']);
        });
    }

    private function genreVisuals(): array
    {
        return [
            'Action' => ['emoji' => '🎬', 'icon_path' => '/images/genres/action.svg'],
            'Church' => ['emoji' => '⛪', 'icon_path' => '/images/genres/church.svg'],
            'Classic' => ['emoji' => '📚', 'icon_path' => '/images/genres/classic.svg'],
            'Computer' => ['emoji' => '💻', 'icon_path' => '/images/genres/computer.svg'],
            'Fantasy' => ['emoji' => '🧙', 'icon_path' => '/images/genres/fantasy.svg'],
            'General Fiction' => ['emoji' => '📖', 'icon_path' => '/images/genres/general-fiction.svg'],
            'Historical Fiction' => ['emoji' => '🏺', 'icon_path' => '/images/genres/historical-fiction.svg'],
            'History' => ['emoji' => '🏛️', 'icon_path' => '/images/genres/history.svg'],
            'Kids' => ['emoji' => '🧸', 'icon_path' => '/images/genres/kids.svg'],
            'LitRPG' => ['emoji' => '🎮', 'icon_path' => '/images/genres/litrpg.svg'],
            'Mystery' => ['emoji' => '🔎', 'icon_path' => '/images/genres/mystery.svg'],
            'Non Fiction' => ['emoji' => '🧠', 'icon_path' => '/images/genres/non-fiction.svg'],
            'Other' => ['emoji' => '🗂️', 'icon_path' => '/images/genres/other.svg'],
            'Religion' => ['emoji' => '🙏', 'icon_path' => '/images/genres/religion.svg'],
            'Romance' => ['emoji' => '💖', 'icon_path' => '/images/genres/romance.svg'],
            'Science' => ['emoji' => '🔬', 'icon_path' => '/images/genres/science.svg'],
            'Science Fiction' => ['emoji' => '🚀', 'icon_path' => '/images/genres/science-fiction.svg'],
        ];
    }
};
