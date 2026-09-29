<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Imports\ImportDraft;
use App\Models\User;
use App\Services\Imports\ImportApiException;
use App\Services\Imports\ImportDraftPresenter;
use App\Services\Imports\ImportDraftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * imports.v1 draft endpoints used by the Kotlin desktop and terminal importers.
 */
class ImportDraftController extends Controller
{
    public function __construct(
        private readonly ImportDraftService $draftService,
        private readonly ImportDraftPresenter $presenter,
    ) {
    }

    public function capabilities(Request $request): JsonResponse
    {
        return response()->json($this->draftService->capabilities($this->user($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $page = $this->draftService->listForUser(
            $this->user($request),
            $request->query('state') === null ? null : (string) $request->query('state'),
            $request->query('cursor') === null ? null : (string) $request->query('cursor'),
        );

        return response()->json([
            'contract_version' => config('import_drafts.contract_version'),
            'data' => $page['drafts']->map(fn (ImportDraft $draft) => $this->presenter->draft($draft))->all(),
            'next_cursor' => $page['next_cursor'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $draft = $this->draftService->create($this->user($request), $request->json()->all());

        return $this->draftResponse($draft, 201);
    }

    public function show(Request $request, string $draftId): JsonResponse|Response
    {
        $draft = $this->draftService->findForUser($this->user($request), $draftId);
        $etag = $this->presenter->etag($draft);
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag);
        }

        return $this->draftResponse($draft, 200);
    }

    public function cancel(Request $request, string $draftId): JsonResponse
    {
        $reason = $request->input('reason');
        if ($reason !== null && (!is_string($reason) || mb_strlen($reason) > 500)) {
            throw ImportApiException::validation('The cancel reason is too long.');
        }
        $draft = $this->draftService->cancel(
            $this->user($request),
            $draftId,
            $this->expectedRevision($request),
            $reason
        );

        return $this->draftResponse($draft, 200);
    }

    private function draftResponse(ImportDraft $draft, int $status): JsonResponse
    {
        return response()
            ->json($this->presenter->envelope($draft), $status)
            ->header('ETag', $this->presenter->etag($draft));
    }

    private function expectedRevision(Request $request): ?int
    {
        $ifMatch = $request->header('If-Match');
        if ($ifMatch === null) {
            return null;
        }
        if (preg_match('/^(?:W\/)?"([1-9][0-9]*)"$/', trim($ifMatch), $matches) !== 1) {
            throw ImportApiException::validation('The If-Match header must be a quoted revision number.');
        }

        return (int) $matches[1];
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (!$user instanceof User) {
            throw new ImportApiException(401, 'unauthenticated', 'Please sign in again.');
        }

        return $user;
    }
}
