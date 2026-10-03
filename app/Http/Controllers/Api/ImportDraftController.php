<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Imports\ImportDraft;
use App\Models\User;
use App\Services\Imports\ImportApiException;
use App\Services\Imports\ImportDraftPresenter;
use App\Services\Imports\ImportDraftReviewService;
use App\Services\Imports\ImportDraftService;
use App\Services\Imports\ImportEvidenceService;
use App\Services\Imports\ImportPlanService;
use App\Services\Imports\ImportTransferService;
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
        private readonly ImportDraftReviewService $reviewService,
        private readonly ImportPlanService $planService,
        private readonly ImportTransferService $transferService,
        private readonly ImportEvidenceService $evidenceService,
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

    public function update(Request $request, string $draftId): JsonResponse
    {
        $draft = $this->reviewService->update(
            $this->user($request),
            $draftId,
            $this->expectedRevision($request),
            $this->jsonBody($request)
        );

        return $this->draftResponse($draft, 200);
    }

    public function recheck(Request $request, string $draftId): JsonResponse
    {
        $draft = $this->draftService->recheck($this->user($request), $draftId, $this->expectedRevision($request));

        return $this->draftResponse($draft, 200);
    }

    public function enrichment(Request $request, string $draftId): JsonResponse
    {
        $result = $this->reviewService->enrichmentComparison($this->user($request), $draftId);

        return response()->json([
            'contract_version' => config('import_drafts.contract_version'),
            'draft_revision' => $result['draft']->revision,
            'fields' => $result['fields'],
        ]);
    }

    public function approve(Request $request, string $draftId): JsonResponse
    {
        $draft = $this->planService->approve(
            $this->user($request),
            $draftId,
            $this->expectedRevision($request),
            $this->jsonBody($request)
        );

        return $this->draftResponse($draft, 200);
    }

    public function events(Request $request, string $draftId): JsonResponse
    {
        $after = $request->query('after', $request->header('Last-Event-ID'));
        $page = $this->draftService->eventsForUser(
            $this->user($request),
            $draftId,
            is_string($after) ? $after : null
        );

        return response()->json([
            'contract_version' => config('import_drafts.contract_version'),
            'draft' => [
                'id' => $page['draft']->public_id,
                'revision' => $page['draft']->revision,
                'state' => $page['draft']->state->value,
            ],
            'data' => $page['events']->map(fn ($event) => $this->presenter->event($page['draft'], $event))->all(),
            'next_cursor' => $page['next_cursor'],
            'has_more' => $page['has_more'],
        ])->header('ETag', $this->presenter->etag($page['draft']));
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

    public function createUploads(Request $request, string $draftId): JsonResponse
    {
        $result = $this->transferService->createSessions(
            $this->user($request),
            $draftId,
            $this->expectedRevision($request),
            $this->jsonBody($request)
        );

        return response()->json([
            'contract_version' => config('import_drafts.contract_version'),
            'chunk_size' => (int) config('import_drafts.max_upload_chunk_bytes'),
            'uploads' => $result['uploads'],
        ], 201)->header('ETag', $this->presenter->etag($result['draft']));
    }

    public function uploadOffset(Request $request, string $draftId, string $fileId): Response
    {
        $offset = $this->transferService->offset($this->user($request), $draftId, $fileId);

        return $this->uploadResponse($offset['offset'])->header('Upload-Length', (string) $offset['length']);
    }

    public function appendUpload(Request $request, string $draftId, string $fileId): Response
    {
        $body = $request->getContent(true);
        $offset = $this->transferService->append(
            $this->user($request),
            $draftId,
            $fileId,
            $request->header('Content-Type'),
            $request->header('Upload-Offset'),
            $request->header('Content-Length'),
            $body
        );

        return $this->uploadResponse($offset);
    }

    public function verify(Request $request, string $draftId): JsonResponse
    {
        $draft = $this->transferService->verify($this->user($request), $draftId, $this->expectedRevision($request));

        return $this->draftResponse($draft, 202);
    }

    public function answerEvidence(Request $request, string $draftId, string $requestId): JsonResponse
    {
        $draft = $this->evidenceService->answer($this->user($request), $draftId, $requestId, $this->jsonBody($request));

        return $this->draftResponse($draft, 200);
    }

    private function uploadResponse(int $offset): Response
    {
        return response('', 204)
            ->header('Upload-Offset', (string) $offset)
            ->header('Tus-Resumable', '1.0.0')
            ->header('Cache-Control', 'no-store');
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        if (!is_array($decoded)) {
            throw ImportApiException::validation('The request body must be a JSON object.');
        }

        return $decoded;
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
