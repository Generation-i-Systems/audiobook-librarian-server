<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BookAutocompleteController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    /**
     * Provides autocomplete suggestions for author names.
     */
    public function autocompleteAuthors(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = $request->input('term', '');
        if (empty($term)) {
            return response()->json([]);
        }
        $authors = $this->documentStoreService->searchAuthorsByName($term);

        return response()->json($authors);
    }

    /**
     * Provides autocomplete suggestions for series titles.
     * Supports both 'term' (legacy) and 'query' (book-autocomplete.js) parameters.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function autocompleteSeries(Request $request): \Illuminate\Http\JsonResponse
    {
        // Support both 'term' (legacy) and 'query' (book-autocomplete.js) parameters
        $term = $request->input('query', $request->input('term', ''));
        $limit = $request->input('limit', 10);

        if (empty($term) || strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        // Get series data from document store (only pass the search term)
        $series = $this->documentStoreService->searchSeriesByName($term);

        // Apply limit after fetching results
        if (count($series) > $limit) {
            $series = array_slice($series, 0, $limit);
        }

        // Format response based on whether we're using the legacy or new format
        if ($request->has('query')) {
            // New format for book-autocomplete.js
            // Use 'seriesName' field instead of 'name' for series documents
            $seriesNames = collect($series)->pluck('seriesName')->unique()->values()->all();
            return response()->json(['data' => $seriesNames]);
        } else {
            // Legacy format
            return response()->json($series);
        }
    }

    /**
     * Provides autocomplete suggestions for narrator names.
     */
    public function autocompleteNarrators(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = $request->input('term', '');
        if (empty($term)) {
            return response()->json([]);
        }
        $narrators = $this->documentStoreService->searchNarratorsByName($term);

        return response()->json($narrators);
    }

    /**
     * Provides autocomplete suggestions for genre names.
     */
    public function autocompleteGenres(Request $request): \Illuminate\Http\JsonResponse
    {
        $term = $request->input('query', $request->input('term', ''));
        if (empty($term)) {
            return response()->json([]);
        }
        $genres = $this->documentStoreService->searchGenresByName($term);

        return response()->json($genres);
    }
}
