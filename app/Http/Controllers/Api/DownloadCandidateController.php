<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DownloadCandidate;
use App\Models\PendingDownload;
use App\Services\AudiobookBayApiService;
use App\Services\PendingDownloadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API Controller for reviewing/approving discovered download candidates
 * (new releases by favorited authors/series found by the discovery job).
 */
class DownloadCandidateController extends Controller
{
    public function __construct(
        protected PendingDownloadService $pendingDownloadService,
        protected AudiobookBayApiService $audiobookBayApiService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->input('status', 'pending');

        $candidates = DownloadCandidate::query()
            ->with(['author:id,name', 'series:id,name'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('discovered_at')
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $candidates,
        ]);
    }

    public function approve(Request $request, DownloadCandidate $downloadCandidate): JsonResponse
    {
        if ($downloadCandidate->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Candidate is not pending.',
            ], 422);
        }

        if (empty($downloadCandidate->magnet_uri)) {
            $details = $this->audiobookBayApiService->getAudiobookDetails($downloadCandidate->abb_url);
            if (!empty($details['magnetUri'])) {
                $downloadCandidate->magnet_uri = $details['magnetUri'];
                $downloadCandidate->save();
            }
        }

        if (empty($downloadCandidate->magnet_uri)) {
            return response()->json([
                'success' => false,
                'message' => 'Could not find a magnet link for this candidate. Try again later.',
            ], 422);
        }

        $infohash = $this->pendingDownloadService->extractInfohash($downloadCandidate->magnet_uri);

        $pendingDownload = PendingDownload::create([
            'magnet_infohash' => $infohash,
            'release_name' => $downloadCandidate->title,
            'torrent_name_hint' => $this->pendingDownloadService->normalizeNameHint($downloadCandidate->title),
            'abb_url' => $downloadCandidate->abb_url,
            'abb_category' => $downloadCandidate->genre,
            'magnet_uri' => $downloadCandidate->magnet_uri,
            'book_count' => 1,
            'status' => 'pending',
            'created_by_user_id' => $request->user()?->id,
            'expires_at' => now()->addDays(14),
        ]);

        $pendingDownload->books()->create([
            'sort_order' => 0,
            'title' => $downloadCandidate->title,
            'authors' => $downloadCandidate->author ? [$downloadCandidate->author->name] : [],
            'genre' => $downloadCandidate->genre,
            'description' => $downloadCandidate->description,
            'cover_url' => $downloadCandidate->cover_url,
            'series_name' => $downloadCandidate->series?->name,
            'abb_url' => $downloadCandidate->abb_url,
        ]);

        $downloadCandidate->update([
            'status' => 'approved',
            'decided_at' => now(),
            'decided_by_user_id' => $request->user()?->id,
            'pending_download_id' => $pendingDownload->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'candidate' => $downloadCandidate,
                'magnet_uri' => $downloadCandidate->magnet_uri,
                'pending_download_id' => $pendingDownload->id,
            ],
        ]);
    }

    public function reject(Request $request, DownloadCandidate $downloadCandidate): JsonResponse
    {
        if ($downloadCandidate->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Candidate is not pending.',
            ], 422);
        }

        $downloadCandidate->update([
            'status' => 'rejected',
            'decided_at' => now(),
            'decided_by_user_id' => $request->user()?->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $downloadCandidate,
        ]);
    }

    public function markSent(DownloadCandidate $downloadCandidate): JsonResponse
    {
        if ($downloadCandidate->status !== 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Candidate has not been approved yet.',
            ], 422);
        }

        $downloadCandidate->update(['status' => 'sent']);

        return response()->json([
            'success' => true,
            'data' => $downloadCandidate,
        ]);
    }
}
