<?php

use App\Models\Skin;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$sourceDir = '/home/eric-22/src/audiobook-librarian-client-skins-beta/skins';

if (!is_dir($sourceDir)) {
    echo "Source directory not found: $sourceDir\n";
    exit(1);
}

$user = User::first();
if (!$user) {
    echo "No user found to assign skins to. Creating one.\n";
    $user = User::create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => bcrypt('password'),
    ]);
}
$userId = $user->id;

$iterator = new DirectoryIterator($sourceDir);
foreach ($iterator as $fileinfo) {
    if ($fileinfo->isDot() || !$fileinfo->isDir()) {
        continue;
    }

    $skinDir = $fileinfo->getPathname();
    $manifestPath = $skinDir . '/manifest.json';

    if (!file_exists($manifestPath)) {
        echo "Skipping $skinDir: No manifest.json\n";
        continue;
    }

    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (!$manifest) {
        echo "Skipping $skinDir: Invalid manifest.json\n";
        continue;
    }

    $skinName = $manifest['skinName'] ?? $fileinfo->getFilename();
    echo "Processing $skinName...\n";

    // check if skin exists
    $existing = Skin::where('name', $skinName)->first();
    if ($existing) {
        echo "Skin already exists, skipping.\n";
        continue;
    }

    // Create Skin record
    $skin = Skin::create([
        'name' => $skinName,
        'author' => $manifest['author'] ?? 'Unknown',
        'version' => $manifest['version'] ?? '1.0',
        'description' => $manifest['description'] ?? '',
        'user_id' => $userId,
        'file_path' => 'temp',
        'file_size' => 0,
        'manifest' => $manifest,
        'is_public' => true,
        'download_count' => 0,
        'average_rating' => 0,
        'rating_count' => 0,
    ]);

    // Zip directory
    $zipPath = storage_path("app/skins/{$skin->id}/skin.zip");
    if (!is_dir(dirname($zipPath))) {
        mkdir(dirname($zipPath), 0755, true);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        echo "Failed to create zip for $skinName\n";
        $skin->delete();
        continue;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($skinDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($skinDir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }
    $zip->close();

    // Update file info
    $skin->file_path = "skins/{$skin->id}/skin.zip";
    $skin->file_size = filesize($zipPath);

    // Handle preview
    $previewImage = $manifest['previewImage'] ?? 'preview.png';
    $previewSource = $skinDir . '/' . $previewImage;
    if (file_exists($previewSource)) {
        $previewDest = "skin-previews/{$skin->id}.png";
        Storage::disk('public')->put($previewDest, file_get_contents($previewSource));
        $skin->preview_path = $previewDest;
    }

    $skin->save();
    echo "Imported $skinName (ID: {$skin->id})\n";
}

echo "Done.\n";
