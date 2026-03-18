<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Author;
use App\Models\Genre;
use App\Models\Narrator;
use App\Models\Series;
use Illuminate\Support\Facades\Log;

class TaxonomyService
{
    public function autocompleteAuthors(string $query, int $limit = 10): array
    {
        return Author::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function autocompleteNarrators(string $query, int $limit = 10): array
    {
        return Narrator::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function autocompleteSeries(string $query, int $limit = 10): array
    {
        return Series::where('name', 'like', "%$query%")->limit($limit)->get()->toArray();
    }

    public function updateGenre(string $id, array $data): bool
    {
        try {
            $genre = Genre::find($id);

            if (!$genre) {
                return false;
            }

            return $genre->update($data);
        } catch (\Exception $e) {
            Log::error('MySqlService updateGenre failed: ' . $e->getMessage());

            return false;
        }
    }

    public function getSeriesByName(string $name): ?array
    {
        $series = Series::where('name', $name)->first();

        return $series ? $series->toArray() : null;
    }

    public function findOrCreateSeriesByName(string $name): array
    {
        $series = $this->getSeriesByName($name);

        if ($series) {
            return $series;
        }

        $id = $this->createSeries($name);

        return ['id' => $id, 'name' => $name];
    }

    public function getSeries(string $id): ?Series
    {
        return Series::find($id);
    }

    public function searchSeriesByName(string $term): array
    {
        return $this->autocompleteSeries($term);
    }

    public function createAuthor(array $data): Author
    {
        return Author::create($data);
    }

    public function searchAuthorsByName(string $term): array
    {
        return $this->autocompleteAuthors($term);
    }

    public function searchNarratorsByName(string $term): array
    {
        return $this->autocompleteNarrators($term);
    }

    public function searchGenresByName(string $term): array
    {
        if (empty($term)) {
            return [];
        }

        return Genre::where('name', 'LIKE', '%' . $term . '%')
            ->orderBy('name')
            ->limit(20)
            ->pluck('name')
            ->toArray();
    }

    public function createGenre(array $data): Genre
    {
        return Genre::create($data);
    }

    public function createNarrator(array $data): Narrator
    {
        return Narrator::create($data);
    }

    public function createSeries(string $name, bool $isCollection = false): ?int
    {
        try {
            $series = Series::create([
                'name' => $name,
                'is_collection' => $isCollection,
            ]);

            return $series->id;
        } catch (\Exception $e) {
            Log::error('MySqlService createSeries failed: ' . $e->getMessage());

            return null;
        }
    }

    public function updateSeries(int $id, array $data): bool
    {
        try {
            $series = Series::find($id);

            if (!$series) {
                return false;
            }

            $series->update($data);

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService updateSeries failed: ' . $e->getMessage());

            return false;
        }
    }

    public function updateAuthor(string $id, array $data): bool
    {
        try {
            $author = Author::find($id);

            if (!$author) {
                return false;
            }

            return $author->update($data);
        } catch (\Exception $e) {
            Log::error('MySqlService updateAuthor failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteNarrator(string $narratorId): bool
    {
        try {
            $narrator = Narrator::where('id', $narratorId)->first();

            if (!$narrator) {
                return false;
            }

            $narrator->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteNarrator failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteSeries(string $seriesId): bool
    {
        try {
            $series = Series::where('id', $seriesId)->first();

            if (!$series) {
                return false;
            }

            $series->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteSeries failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteGenre(string $genreId): bool
    {
        try {
            $genre = Genre::where('id', $genreId)->first();

            if (!$genre) {
                return false;
            }

            $genre->delete();

            return true;
        } catch (\Exception $e) {
            Log::error('MySqlService deleteGenre failed: ' . $e->getMessage());

            return false;
        }
    }

    public function deleteAuthor(string $authorId): void
    {
        try {
            $author = Author::where('id', $authorId)->first();

            if (!$author) {
                return;
            }

            $author->delete();
        } catch (\Exception $e) {
            Log::error('MySqlService deleteAuthor failed: ' . $e->getMessage());
        }
    }
}
