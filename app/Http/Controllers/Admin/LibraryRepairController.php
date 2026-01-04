<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Enums\LibraryRepairIssueType;
use App\Http\Controllers\Controller;
use App\Services\LibraryRepairService;
use App\Services\AudiobookBayService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Illuminate\Support\Str;

class LibraryRepairController extends Controller
{
    public function __construct(
        private DocumentStoreServiceInterface $documentStore,
        private LibraryRepairService $libraryRepairService,
        private AudiobookBayService $audiobookBayService
    ) {
    }

    public function index(Request $request): View
    {
        $page = max(1, (int) $request->integer('page', 1));
        $limit = max(1, min(100, (int) $request->integer('limit', 25)));

        $filters = $this->buildFilters($request);

        $issues = $this->prepareIssuesForDisplay(
            $this->documentStore->listLibraryRepairIssues($filters, $limit, $page)
        );
        $total = $this->documentStore->countLibraryRepairIssues($filters);

        $paginator = new LengthAwarePaginator(
            collect($issues),
            $total,
            $limit,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('admin.library-repair.index', [
            'issues' => $paginator,
            'issueTypes' => $this->issueTypeOptions(),
            'filters' => [
                'issue_type' => $filters['issue_type'] ?? '',
                'search' => $filters['search'] ?? '',
                'limit' => $limit,
                'show_resolved' => $filters['show_resolved'] ?? false,
            ],
        ]);
    }

    public function resolve(Request $request, int $issueId): RedirectResponse
    {
        $request->validate([
            'resolution_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $notes = $request->input('resolution_notes');
        $resolved = $this->documentStore->resolveLibraryRepairIssue($issueId, $notes);

        if (!$resolved) {
            return redirect()
                ->back()
                ->with('error', 'Unable to resolve library repair issue. It may have already been resolved.')
            ;
        }

        return redirect()
            ->back()
            ->with('success', 'Library repair issue resolved.')
        ;
    }

    public function rescan(int $issueId): RedirectResponse
    {
        try {
            $result = $this->libraryRepairService->rescanIssue($issueId);

            return redirect()
                ->back()
                ->with($result['status'] === 'resolved' ? 'success' : 'info', $result['message'])
            ;
        } catch (\Throwable $e) {
            Log::error('library-repair.rescan failed', [
                'issueId' => $issueId,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'Unable to rescan issue: ' . $e->getMessage())
            ;
        }
    }

    public function importMissingDirectory(Request $request, int $issueId): RedirectResponse
    {
        $request->validate([
            'import_path' => ['required', 'string'],
        ]);

        try {
            $result = $this->libraryRepairService->importMissingDirectoryIssue(
                $issueId,
                $request->input('import_path')
            );

            return redirect()
                ->back()
                ->with($result['status'] === 'resolved' ? 'success' : 'info', $result['message'])
            ;
        } catch (\Throwable $e) {
            Log::error('library-repair.import-missing failed', [
                'issueId' => $issueId,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'Unable to import directory: ' . $e->getMessage())
            ;
        }
    }

    /**
     * @return array<string,array-key>
     */
    private function issueTypeOptions(): array
    {
        $options = [];

        foreach (LibraryRepairIssueType::cases() as $case) {
            $options[$case->value] = str($case->value)
                ->replace('_', ' ')
                ->headline()
                ->toString()
            ;
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(Request $request): array
    {
        $filters = [];

        if ($request->filled('issue_type')) {
            $filters['issue_type'] = $request->input('issue_type');
        }

        if ($request->filled('search')) {
            $filters['search'] = $request->input('search');
        }

        $filters['show_resolved'] = $request->boolean('show_resolved', false);

        if (!$filters['show_resolved']) {
            $filters['status'] = 'pending';
        }

        return $filters;
    }

    /**
     * @param array<int,array<string,mixed>> $issues
     *
     * @return array<int,array<string,mixed>>
     */
    private function prepareIssuesForDisplay(array $issues): array
    {
        return collect($issues)
            ->map(function (array $issue): array {
                $issueType = $issue['issueType'] ?? '';
                $issue['issueLabel'] = str($issueType)->replace('_', ' ')->headline()->toString();
                $issue['isResolved'] = ($issue['status'] ?? '') === 'resolved';
                $issue['audiobookBayUrl'] = $this->buildAudiobookBayUrl($issue);

                return $issue;
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string,mixed> $issue
     */
    private function buildAudiobookBayUrl(array $issue): ?string
    {
        if (($issue['issueType'] ?? '') !== LibraryRepairIssueType::MISSING_DIRECTORY->value) {
            return null;
        }

        if (($issue['status'] ?? '') !== 'pending') {
            return null;
        }

        $book = $issue['book'] ?? null;

        if (!is_array($book) || empty($book['title'])) {
            return null;
        }

        $author = $book['authors'][0] ?? '';
        $title = $book['title'] ?? '';
        $raw = trim(implode(' ', array_filter([$author, $title])));

        if ($raw === '') {
            $raw = 'audiobook';
        }

        $normalized = Str::of($raw)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/iu', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            $normalized = 'audiobook';
        }

        return $this->audiobookBayService->buildSearchUrl($normalized);
    }
}
