[1;33mdiff --git a/app/Console/Commands/ShowBookInfo.php b/app/Console/Commands/ShowBookInfo.php[m
[1;33mindex a258b9c5..e016e063 100644[m
[1;33m--- a/app/Console/Commands/ShowBookInfo.php[m
[1;33m+++ b/app/Console/Commands/ShowBookInfo.php[m
[1;35m@@ -19,8 +19,6 @@[m [mclass ShowBookInfo extends Command[m
 {[m
     use BookImportTrait;[m
 [m
[31m-    private ?string $resolvedBookRoot = null;[m
[31m-[m
     private array $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'opus', 'aac', 'wav', 'wma'];[m
 [m
     protected $signature = 'books:info {directories?*}[m
[1;35m@@ -1067,7 +1065,6 @@[m [mprotected function hasUpdateOptions(): bool[m
 [m
     protected function hasAudioFiles(string $directory): bool[m
     {[m
[31m-        $audioExtensions = ['mp3', 'm4a', 'm4b', 'flac', 'ogg', 'opus', 'wav'];[m
         $files = new \RecursiveIteratorIterator([m
             new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),[m
             \RecursiveIteratorIterator::SELF_FIRST[m
[1;35m@@ -1076,17 +1073,20 @@[m [mprotected function hasAudioFiles(string $directory): bool[m
         foreach ($files as $file) {[m
             if ($file->isFile()) {[m
                 $extension = strtolower($file->getExtension());[m
[31m-                if (in_array($extension, $audioExtensions)) {[m
[32m+[m[32m                if (in_array($extension, $this->audioExtensions)) {[m
                     return true;[m
                 }[m
             }[m
         }[m
 [m
[32m+[m[32m        return false;[m
[32m+[m[32m    }[m
[32m+[m[32m            }[m
[32m+[m[32m        }[m
[32m+[m
         return false;[m
     }[m
 [m
[31m-    protected function updateBookFields(Book $book, ?string $directory): void[m
[31m-    {[m
         $updated = false;[m
         $bookRoot = config('app.book_root', '/media/lyra_data1/audiobooks/books');[m
         // Resolve symlinks in book root for consistent path handling[m
