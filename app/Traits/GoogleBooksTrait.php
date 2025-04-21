<?php

namespace App\Traits;

use App\Models\Genre;
use App\Services\GoogleBooksApiService;
use Illuminate\Support\Facades\Storage;

trait GoogleBooksTrait
{
    protected $googleBooksApiService;

    public function __construct(GoogleBooksApiService $googleBooksApiService)
    {
        $this->googleBooksApiService = $googleBooksApiService;
    }

    public function searchGoogleBooks($query)
    {
        return $this->googleBooksApiService->searchBooks($query);
    }

    public function getBookDetails($volumeId)
    {
        return $this->googleBooksApiService->getBookDetails($volumeId);
    }

    private function saveCoverImage($imageUrl)
    {
        try {
            $imageContents = file_get_contents($imageUrl);  //Downloads contents

            if ($imageContents === false) {
                return null;  //Error.
            }

            $filename = 'covers/' . uniqid() . '.jpg'; // Generate a unique file name
            Storage::disk('public')->put($filename, $imageContents); //Store in storage/app/public/covers
            return $filename;

        } catch (\Exception $e) {
            return null;
        }
    }
}
