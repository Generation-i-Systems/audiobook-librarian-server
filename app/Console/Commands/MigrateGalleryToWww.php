<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Skin;
use App\Models\SkinCustomization;
use App\Models\SkinRating;
use App\Models\Theme;
use App\Models\ThemeRating;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time migration of skins/themes/ratings/customizations (and the users
 * who own them) from this app's database into audiobook-librarian-www's
 * database, per Phase 3 of the skin/theme extraction plan.
 *
 * This command is strictly read-only against this app's own database and
 * filesystem — it never writes, deletes, or modifies anything here. All
 * writes happen against the 'www' database connection and WWW_STORAGE_PATH.
 *
 * Safe to re-run: users are matched/created by email, skins/themes/ratings/
 * customizations are matched by (source table, source id) so re-running
 * does not create duplicates.
 */
class MigrateGalleryToWww extends Command
{
    protected $signature = 'gallery:migrate-to-www {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Migrate skins, themes, ratings, and customizations into audiobook-librarian-www';

    private bool $dryRun = false;

    /** @var array<int,int> server user_id => www user_id */
    private array $userIdMap = [];

    /** @var array<int,int> server skin_id => www skin_id */
    private array $skinIdMap = [];

    /** @var array<int,int> server theme_id => www theme_id */
    private array $themeIdMap = [];

    private string $wwwStoragePath;

    private int $usersCreated = 0;
    private int $usersReused = 0;
    private array $fileWarnings = [];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $this->wwwStoragePath = rtrim((string) config('app.www_storage_path', ''), '/');

        if ($this->wwwStoragePath === '' || ! is_dir($this->wwwStoragePath)) {
            $this->error('WWW_STORAGE_PATH is not set or does not point to a real directory. Set it in .env.');

            return self::FAILURE;
        }

        $this->info($this->dryRun ? 'Running in --dry-run mode. No data will be written.' : 'Running for real. This will write to the www database and storage.');
        $this->newLine();

        DB::connection('www')->beginTransaction();

        try {
            $this->migrateUsers();
            $this->migrateSkins();
            $this->migrateThemes();
            $this->migrateSkinRatings();
            $this->migrateThemeRatings();
            $this->migrateSkinCustomizations();

            if ($this->dryRun) {
                DB::connection('www')->rollBack();
            } else {
                DB::connection('www')->commit();
            }
        } catch (\Throwable $e) {
            DB::connection('www')->rollBack();
            $this->error('Migration failed, rolled back: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            return self::FAILURE;
        }

        $this->printSummary();

        return self::SUCCESS;
    }

    private function migrateUsers(): void
    {
        $userIds = collect()
            ->merge(Skin::withTrashed()->pluck('user_id'))
            ->merge(Theme::withTrashed()->pluck('user_id'))
            ->merge(SkinRating::pluck('user_id'))
            ->merge(ThemeRating::pluck('user_id'))
            ->merge(SkinCustomization::pluck('user_id'))
            ->unique()
            ->values();

        $this->info("Found {$userIds->count()} distinct users to provision.");

        foreach ($userIds as $serverId) {
            $user = User::withTrashed()->find($serverId);
            if (! $user) {
                $this->warn("  user_id={$serverId} referenced by gallery content but does not exist — skipping (orphaned FK).");

                continue;
            }

            $existing = DB::connection('www')->table('users')->where('email', $user->email)->first();

            if ($existing) {
                $this->userIdMap[$serverId] = $existing->id;
                $this->usersReused++;

                continue;
            }

            $role = in_array($user->role, ['admin', 'super-admin'], true) ? $user->role : 'user';

            $wwwId = DB::connection('www')->table('users')->insertGetId([
                'name' => $user->name,
                'email' => $user->email,
                'email_verified_at' => $user->email_verified_at,
                'password' => $user->password, // bcrypt hashes are self-describing/portable across Laravel apps
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->userIdMap[$serverId] = $wwwId;
            $this->usersCreated++;
        }
    }

    private function migrateSkins(): void
    {
        $skins = Skin::withTrashed()->orderBy('id')->get();
        $this->info("Migrating {$skins->count()} skins...");

        // Pass 1: insert every skin with forked_from_id left null (targets may not exist yet).
        foreach ($skins as $skin) {
            if (! isset($this->userIdMap[$skin->user_id])) {
                $this->warn("  Skin #{$skin->id} owned by missing user_id={$skin->user_id} — skipping.");

                continue;
            }

            [$zipContents, $zipSourceNote] = $this->resolveSkinZip($skin);

            $wwwId = DB::connection('www')->table('skins')->insertGetId([
                'name' => $skin->name,
                'author' => $skin->author,
                'version' => $skin->version,
                'description' => $skin->description,
                'user_id' => $this->userIdMap[$skin->user_id],
                'forked_from_id' => null,
                'file_path' => '',
                'preview_path' => null,
                'file_size' => $skin->file_size,
                'manifest' => $skin->manifest !== null ? json_encode($skin->manifest) : null,
                'is_public' => $skin->is_public,
                'download_count' => $skin->download_count,
                'average_rating' => $skin->average_rating,
                'rating_count' => $skin->rating_count,
                'created_at' => $skin->created_at,
                'updated_at' => $skin->updated_at,
                'deleted_at' => $skin->deleted_at,
            ]);

            $this->skinIdMap[$skin->id] = $wwwId;

            if ($zipContents !== null) {
                DB::connection('www')->table('skins')->where('id', $wwwId)->update(['file_path' => "{$wwwId}/skin.zip"]);
                $this->writeWwwFile("private/skins/{$wwwId}/skin.zip", $zipContents);
            } else {
                $this->fileWarnings[] = "Skin #{$skin->id} ('{$skin->name}'): no ZIP file found anywhere on disk.";
            }

            $previewContents = $this->resolveSkinPreview($skin);
            if ($previewContents !== null) {
                DB::connection('www')->table('skins')->where('id', $wwwId)->update(['preview_path' => "previews/{$wwwId}.png"]);
                $this->writeWwwFile("public/skin-public/previews/{$wwwId}.png", $previewContents);
            }

            $this->migrateSkinAssets($skin->id, $wwwId);

            if ($zipSourceNote !== null) {
                $this->fileWarnings[] = "Skin #{$skin->id}: {$zipSourceNote}";
            }
        }

        // Pass 2: backfill forked_from_id now that all new IDs exist.
        foreach ($skins as $skin) {
            if (! $skin->forked_from_id || ! isset($this->skinIdMap[$skin->id]) || ! isset($this->skinIdMap[$skin->forked_from_id])) {
                continue;
            }

            DB::connection('www')->table('skins')
                ->where('id', $this->skinIdMap[$skin->id])
                ->update(['forked_from_id' => $this->skinIdMap[$skin->forked_from_id]]);
        }
    }

    /**
     * Resolve the canonical current ZIP for a skin, matching the same
     * priority order the original Api\SkinController::download() fallback
     * used, plus a last-resort fallback to the newest repair artifact for
     * skins that have no clean file left at all.
     *
     * @return array{0: string|null, 1: string|null} [file contents or null, warning note or null]
     */
    private function resolveSkinZip(Skin $skin): array
    {
        $serverRoot = storage_path();

        $candidates = [
            "{$serverRoot}/app/private/{$skin->file_path}",
            "{$serverRoot}/app/{$skin->file_path}",
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return [file_get_contents($path), null];
            }
        }

        // Last resort: the most recently generated repair artifact, if any.
        $legacyDir = "{$serverRoot}/app/skins/{$skin->id}";
        if (is_dir($legacyDir)) {
            $repaired = glob("{$legacyDir}/skin_repaired_*.zip") ?: [];
            if (! empty($repaired)) {
                usort($repaired, fn ($a, $b) => filemtime($b) <=> filemtime($a));
                $newest = $repaired[0];

                return [file_get_contents($newest), 'no clean skin.zip found — used newest repair artifact ' . basename($newest)];
            }
        }

        return [null, null];
    }

    private function resolveSkinPreview(Skin $skin): ?string
    {
        if (! $skin->preview_path) {
            return null;
        }

        $path = storage_path("app/public/{$skin->preview_path}");

        return is_file($path) ? file_get_contents($path) : null;
    }

    private function migrateSkinAssets(int $serverSkinId, int $wwwSkinId): void
    {
        $assetDir = storage_path("app/public/skins/{$serverSkinId}/assets");
        if (! is_dir($assetDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($assetDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($assetDir, '', $file->getPathname()), '/');
            $this->writeWwwFile("public/skin-public/{$wwwSkinId}/assets/{$relative}", file_get_contents($file->getPathname()));
        }
    }

    private function migrateThemes(): void
    {
        $themes = Theme::withTrashed()->orderBy('id')->get();
        $this->info("Migrating {$themes->count()} themes...");

        foreach ($themes as $theme) {
            if (! isset($this->userIdMap[$theme->user_id])) {
                $this->warn("  Theme #{$theme->id} owned by missing user_id={$theme->user_id} — skipping.");

                continue;
            }

            $wwwId = DB::connection('www')->table('themes')->insertGetId([
                'name' => $theme->name,
                'author' => $theme->author,
                'version' => $theme->version,
                'description' => $theme->description,
                'user_id' => $this->userIdMap[$theme->user_id],
                'forked_from_id' => null,
                'theme_data' => json_encode($theme->theme_data),
                'is_public' => $theme->is_public,
                'download_count' => $theme->download_count,
                'average_rating' => $theme->average_rating,
                'rating_count' => $theme->rating_count,
                'created_at' => $theme->created_at,
                'updated_at' => $theme->updated_at,
                'deleted_at' => $theme->deleted_at,
            ]);

            $this->themeIdMap[$theme->id] = $wwwId;
        }

        foreach ($themes as $theme) {
            if (! $theme->forked_from_id || ! isset($this->themeIdMap[$theme->id]) || ! isset($this->themeIdMap[$theme->forked_from_id])) {
                continue;
            }

            DB::connection('www')->table('themes')
                ->where('id', $this->themeIdMap[$theme->id])
                ->update(['forked_from_id' => $this->themeIdMap[$theme->forked_from_id]]);
        }
    }

    private function migrateSkinRatings(): void
    {
        $ratings = SkinRating::orderBy('id')->get();
        $this->info("Migrating {$ratings->count()} skin ratings...");

        foreach ($ratings as $rating) {
            if (! isset($this->userIdMap[$rating->user_id]) || ! isset($this->skinIdMap[$rating->skin_id])) {
                continue;
            }

            DB::connection('www')->table('skin_ratings')->insert([
                'skin_id' => $this->skinIdMap[$rating->skin_id],
                'user_id' => $this->userIdMap[$rating->user_id],
                'rating' => $rating->rating,
                'comment' => $rating->comment,
                'created_at' => $rating->created_at,
                'updated_at' => $rating->updated_at,
            ]);
        }
    }

    private function migrateThemeRatings(): void
    {
        $ratings = ThemeRating::orderBy('id')->get();
        $this->info("Migrating {$ratings->count()} theme ratings...");

        foreach ($ratings as $rating) {
            if (! isset($this->userIdMap[$rating->user_id]) || ! isset($this->themeIdMap[$rating->theme_id])) {
                continue;
            }

            DB::connection('www')->table('theme_ratings')->insert([
                'theme_id' => $this->themeIdMap[$rating->theme_id],
                'user_id' => $this->userIdMap[$rating->user_id],
                'rating' => $rating->rating,
                'comment' => $rating->comment,
                'created_at' => $rating->created_at,
                'updated_at' => $rating->updated_at,
            ]);
        }
    }

    private function migrateSkinCustomizations(): void
    {
        $customizations = SkinCustomization::orderBy('id')->get();
        $this->info("Migrating {$customizations->count()} skin customizations...");

        foreach ($customizations as $customization) {
            if (! isset($this->userIdMap[$customization->user_id]) || ! isset($this->skinIdMap[$customization->skin_id])) {
                continue;
            }

            $wwwSkinId = $this->skinIdMap[$customization->skin_id];
            $newFilePath = null;

            if ($customization->file_path) {
                $source = storage_path("app/public/{$customization->file_path}");
                if (is_file($source)) {
                    $newFilePath = "customizations/{$wwwSkinId}/" . basename($customization->file_path);
                    $this->writeWwwFile("public/skin-public/{$newFilePath}", file_get_contents($source));
                }
            }

            DB::connection('www')->table('skin_customizations')->insert([
                'skin_id' => $wwwSkinId,
                'user_id' => $this->userIdMap[$customization->user_id],
                'type' => $customization->type,
                'value' => $customization->value,
                'file_path' => $newFilePath,
                'visibility' => $customization->visibility,
                'created_at' => $customization->created_at,
                'updated_at' => $customization->updated_at,
            ]);
        }
    }

    private function writeWwwFile(string $relativePath, string $contents): void
    {
        if ($this->dryRun) {
            return;
        }

        $fullPath = "{$this->wwwStoragePath}/app/{$relativePath}";
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($fullPath, $contents);
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->info('=== Migration summary ===');
        $this->line("Users:  created={$this->usersCreated} reused={$this->usersReused}");
        $this->line('Skins:  ' . count($this->skinIdMap) . ' migrated');
        $this->line('Themes: ' . count($this->themeIdMap) . ' migrated');

        if (! empty($this->fileWarnings)) {
            $this->newLine();
            $this->warn('File warnings (' . count($this->fileWarnings) . '):');
            foreach ($this->fileWarnings as $warning) {
                $this->warn("  - {$warning}");
            }
        }

        if ($this->dryRun) {
            $this->newLine();
            $this->comment('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }
    }
}
