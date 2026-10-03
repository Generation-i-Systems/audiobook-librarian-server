<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Imports\ImportApiException;
use App\Services\Imports\ImportBookDiscovery;
use App\Services\Imports\ImportDiscoveryRequestValidator;
use App\Services\Imports\ImportDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stateless book discovery for the Kotlin importers: the client lists names and sizes, the library decides
 * what counts as a book with the same rules book:import uses.
 */
class ImportDiscoveryController extends Controller
{
    public function __construct(
        private readonly ImportDraftService $draftService,
        private readonly ImportDiscoveryRequestValidator $validator,
        private readonly ImportBookDiscovery $discovery,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw new ImportApiException(401, 'unauthenticated', 'Please sign in again.');
        }
        $this->draftService->assertCanImport($user);

        $result = $this->discovery->discover($this->validator->validate($request->json()->all()));

        return response()->json(['contract_version' => config('import_drafts.contract_version')] + $result);
    }
}
