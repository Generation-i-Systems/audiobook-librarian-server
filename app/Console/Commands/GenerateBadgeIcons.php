<?php

namespace App\Console\Commands;

use App\Models\Badge;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class GenerateBadgeIcons extends Command
{
    protected $signature = 'badges:generate-icons {--force : Overwrite existing SVG files}';
    protected $description = 'Generate placeholder SVG icon files for all badges under public/images/badges/{key}.svg';

    public function handle(Filesystem $files): int
    {
        $outputDir = public_path('images/badges');
        if (!$files->isDirectory($outputDir)) {
            $files->makeDirectory($outputDir, 0755, true);
            $this->info("Created directory: {$outputDir}");
        }

        $overWrite = (bool) $this->option('force');
        $count = 0;

        $badges = Badge::query()->select(['key', 'name', 'icon'])->orderBy('key')->get();
        foreach ($badges as $badge) {
            $path = $outputDir . DIRECTORY_SEPARATOR . $badge->key . '.svg';
            if ($files->exists($path) && !$overWrite) {
                $this->line("skip: {$badge->key}.svg");
                continue;
            }

            $svg = $this->buildSvg($badge->key, $badge->name, (string)($badge->icon ?? '🎧'));
            $files->put($path, $svg);
            $this->line("write: {$badge->key}.svg");
            $count++;
        }

        $this->info("Generated {$count} SVG file(s).");
        return self::SUCCESS;
    }

    private function buildSvg(string $key, string $name, string $emoji): string
    {
        $safeTitle = htmlspecialchars($name . ' (' . $key . ')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $emojiText = htmlspecialchars($emoji, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 120" width="120" height="120" role="img" aria-label="{$safeTitle}">
  <title>{$safeTitle}</title>
  <defs>
    <linearGradient id="bg" x1="0" x2="1" y1="0" y2="1">
      <stop offset="0%" stop-color="#4facfe"/>
      <stop offset="100%" stop-color="#00f2fe"/>
    </linearGradient>
  </defs>
  <rect x="4" y="4" width="112" height="112" rx="12" ry="12" fill="url(#bg)" stroke="#1b365d" stroke-width="4"/>
  <circle cx="60" cy="50" r="18" fill="#fff" opacity="0.9"/>
  <text x="60" y="56" text-anchor="middle" font-size="20">{$emojiText}</text>
  <text x="60" y="95" text-anchor="middle" font-family="sans-serif" font-size="10" fill="#0a2540">{$key}</text>
</svg>
SVG;
    }
}
