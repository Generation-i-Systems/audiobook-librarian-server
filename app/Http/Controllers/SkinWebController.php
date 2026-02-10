<?php

namespace App\Http\Controllers;

use App\Services\Contracts\SkinServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SkinWebController extends Controller
{
    public function __construct(
        protected SkinServiceInterface $skinService
    ) {
    }

    public function index(Request $request)
    {
        try {
            $search = $request->get('search');
            $sort = $request->get('sort', 'recent');
            $page = $request->get('page', 1);

            $filters = $search ? ['search' => $search] : [];
            $result = $this->skinService->listSkins($filters, (int) $page, 24, (string) $sort);

            return view('gallery.skins.index', [
                'skins' => $result['data'],
                'pagination' => $result['pagination'],
                'search' => $search,
                'sort' => $sort,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list skins: ' . $e->getMessage());

            // @phpstan-ignore-next-line
            return redirect()->back()->with('error', 'Failed to load skins. Please try again.');
        }
    }

    public function show(int $id)
    {
        try {
            $skin = $this->skinService->getSkin($id);

            if (! $skin) {
                return redirect()->route('gallery.skins.index')->with('error', 'Skin not found.');
            }

            return view('gallery.skins.show', ['skin' => $skin]);
        } catch (\Exception $e) {
            Log::error('Failed to show skin: ' . $e->getMessage());

            return redirect()->route('gallery.skins.index')->with('error', 'Failed to load skin.');
        }
    }

    public function create()
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to upload skins.');
        }

        return view('gallery.skins.create');
    }

    public function store(Request $request)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to upload skins.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'version' => 'required|string|max:50',
            'description' => 'nullable|string|max:1000',
            'file' => 'required|file|mimes:zip|max:51200',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            $skin = $this->skinService->createSkin(
                Auth::id(),
                $validated,
                $request->file('file')
            );

            return redirect()->route('gallery.skins.show', $skin['id'])
                ->with('success', 'Skin uploaded successfully!');
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()
                ->withErrors(['file' => $e->getMessage()])
                ->withInput();
        } catch (\Exception $e) {
            Log::error('Failed to create skin: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to upload skin. Please try again.')
                ->withInput();
        }
    }

    public function edit(int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        try {
            $skin = $this->skinService->getSkin($id);

            if (! $skin) {
                return redirect()->route('gallery.skins.index')->with('error', 'Skin not found.');
            }

            if ($skin['userId'] !== Auth::id() && !Auth::user()->is_admin) {
                return redirect()->route('gallery.skins.show', $id)
                    ->with('error', 'You do not have permission to edit this skin.');
            }

            return view('gallery.skins.edit', ['skin' => $skin]);
        } catch (\Exception $e) {
            Log::error('Failed to load skin for editing: ' . $e->getMessage());

            return redirect()->route('gallery.skins.index')
                ->with('error', 'Failed to load skin.');
        }
    }

    public function update(Request $request, int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'is_public' => 'nullable|boolean',
        ]);

        try {
            $this->skinService->updateSkin($id, Auth::id(), $validated);

            return redirect()->route('gallery.skins.show', $id)
                ->with('success', 'Skin updated successfully!');
        } catch (\RuntimeException $e) {
            return redirect()->route('gallery.skins.show', $id)
                ->with('error', 'You do not have permission to edit this skin.');
        } catch (\Exception $e) {
            Log::error('Failed to update skin: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to update skin.')
                ->withInput();
        }
    }

    public function destroy(int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        try {
            $this->skinService->deleteSkin($id, Auth::id());

            return redirect()->route('gallery.skins.index')
                ->with('success', 'Skin deleted successfully!');
        } catch (\RuntimeException $e) {
            return redirect()->route('gallery.skins.show', $id)
                ->with('error', 'You do not have permission to delete this skin.');
        } catch (\Exception $e) {
            Log::error('Failed to delete skin: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to delete skin.');
        }
    }

    public function fork(Request $request, int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to fork skins.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        try {
            $skin = $this->skinService->forkSkin($id, Auth::id(), $validated['name']);

            return redirect()->route('gallery.skins.show', $skin['id'])
                ->with('success', 'Skin forked successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to fork skin: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to fork skin.');
        }
    }

    public function rate(Request $request, int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in to rate skins.');
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        try {
            $this->skinService->rateSkin($id, Auth::id(), $validated['rating'], $validated['comment'] ?? null);

            return redirect()->route('gallery.skins.show', $id)
                ->with('success', 'Rating submitted successfully!');
        } catch (\Exception $e) {
            Log::error('Failed to rate skin: ' . $e->getMessage());

            return redirect()->back()
                ->with('error', 'Failed to submit rating.');
        }
    }

    public function mySkins(Request $request)
    {
        if (! Auth::check()) {
            return redirect()->route('login')->with('error', 'You must be logged in.');
        }

        try {
            $page = $request->get('page', 1);
            $result = $this->skinService->getMySkins(Auth::id(), $page, 24);

            return view('gallery.skins.my-skins', [
                'skins' => $result['data'],
                'pagination' => $result['pagination'],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list my skins: ' . $e->getMessage());

            return redirect()->back()->with('error', 'Failed to load your skins.');
        }
    }

    public function designerNew()
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        try {
            $skin = $this->skinService->createBlankSkin(Auth::id());

            return redirect()->route('gallery.skins.designer', $skin['id']);
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to create new skin: ' . $e->getMessage());
        }
    }

    public function designer(int $id)
    {
        if (! Auth::check()) {
            return redirect()->route('login');
        }

        try {
            $skin = $this->skinService->getSkin($id);
            if (! $skin) {
                abort(404);
            }

            $skinUserId = $skin['user_id'] ?? $skin['userId'] ?? null;
            if ($skinUserId !== Auth::id() && ! Auth::user()->is_admin) {
                return redirect()->route('gallery.skins.show', $id)
                    ->with('error', 'You must fork this skin to edit it.');
            }

            // Ensure assets are extracted for the designer
            $this->skinService->extractAssets($id);

            // Get assets
            $assets = $this->skinService->listAssets($id);

            return view('gallery.skins.designer', [
                'skin' => $skin,
                'assets' => $assets,
            ]);
        } catch (\Exception $e) {
            Log::error('Designer error: ' . $e->getMessage());

            return redirect()->back()->with('error', 'Error loading designer.');
        }
    }

    public function updateManifest(Request $request, int $id)
    {
        $manifest = $request->input('manifest');
        if (is_string($manifest)) {
            $manifest = json_decode($manifest, true);
        }

        try {
            // Update manifest
            $skin = $this->skinService->updateManifest($id, Auth::id(), $manifest);

            // Also update metadata if provided and valid
            $data = $request->only(['name', 'author', 'version']);
            if (!empty($data)) {
                // We reuse updateSkin for name. Author/Version are in manifest but maybe should be in DB too?
                // Skin model has author, version columns.
                // updateSkin only accepts name, description, is_public.
                // We might need to expand updateSkin or just direct update here if service allows.
                // Service updateSkin takes $data array.
                $this->skinService->updateSkin($id, Auth::id(), $data);
            }

            return response()->json(['success' => true, 'skin' => $skin]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function uploadAsset(Request $request, int $id)
    {
        $request->validate([
            'file' => 'required|image|max:2048', // 2MB max
            'path' => 'required|string',
        ]);

        try {
            $url = $this->skinService->addAsset($id, Auth::id(), $request->file('file'), $request->input('path'));

            return response()->json(['success' => true, 'url' => $url]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function listAssets(int $id)
    {
        try {
            $assets = $this->skinService->listAssets($id);

            return response()->json(['success' => true, 'assets' => $assets]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function forkForDesigner(int $id)
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $skin = $this->skinService->getSkin($id);
            $newName = $skin['name'] . ' (Copy)';
            $newSkin = $this->skinService->forkSkin($id, Auth::id(), $newName);

            return response()->json(['success' => true, 'redirect' => route('gallery.skins.designer', $newSkin['id'])]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 400);
        }
    }

    public function exportZip(int $id)
    {
        try {
            $skin = $this->skinService->getSkin($id);
            if (! $skin) {
                abort(404);
            }

            if (! $skin['is_public'] && $skin['user_id'] !== Auth::id() && ! Auth::user()?->is_admin) {
                abort(403);
            }

            $zipPath = $this->skinService->buildZip($id);

            return response()->download($zipPath)->deleteFileAfterSend();
        } catch (\Exception $e) {
            Log::error('Export ZIP error: ' . $e->getMessage());

            return redirect()->back()->with('error', 'Failed to generate ZIP.');
        }
    }
    public function getSampleData(Request $request)
    {
        if (! Auth::check()) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        try {
            $bookId = $request->input('book_id');
            $book = null;

            if ($bookId) {
                $book = \App\Models\Book::with(['authors', 'narrators', 'series'])->find($bookId);
            }

            if (!$book) {
                // Default: Last imported book with a cover
                $book = \App\Models\Book::with(['authors', 'narrators', 'series'])
                    ->whereNotNull('cover_image')
                    ->where('cover_image', '!=', '')
                    ->latest('id')
                    ->first();
            }

            // Available books list
            $availableBooks = \App\Models\Book::select('id', 'title')
                ->whereNotNull('cover_image')
                ->where('cover_image', '!=', '')
                ->latest('id')
                ->limit(30)
                ->get();

            if (!$book) {
                return response()->json([
                   'book.title' => 'The Hitchhiker\'s Guide to the Galaxy',
                   'book.author' => 'Douglas Adams',
                   'book.narrator' => 'Stephen Fry',
                   'book.series' => 'Hitchhiker\'s Guide #1',
                   'book.duration' => '5h 51m',
                   'book.description' => 'Seconds before the Earth is demolished to make way for a galactic freeway...',
                   'cover' => asset('images/placeholder.png'),
                   'available_books' => [],
                   'current_book_id' => null
                ]);
            }

            $coverImage = $book->cover_image;
            if (filter_var($coverImage, FILTER_VALIDATE_URL)) {
                $coverUrl = $coverImage;
            } else {
                $baseName = basename($coverImage);
                $directoryPath = trim($book->directory_path ?? '', '/');
                $fullPath = $directoryPath ? $directoryPath . '/' . $baseName : $baseName;
                $coverUrl = route('cover.proxy', ['path' => $fullPath]);
            }

            $seriesText = '';
            if ($book->series->isNotEmpty()) {
                $s = $book->series->first();
                $seriesText = $s->name;
                if ($s->pivot->series_number) { /** @phpstan-ignore-line */
                    $seriesText .= ' #' . $s->pivot->series_number; /** @phpstan-ignore-line */
                }
            }

            $durationSeconds = $book->duration ?? 0;
            $durationFormatted = sprintf('%02d:%02d:%02d', ($durationSeconds / 3600), ($durationSeconds / 60 % 60), $durationSeconds % 60);

            return response()->json([
                'book.title' => $book->title,
                'book.author' => $book->authors->pluck('name')->implode(', '),
                'book.narrator' => $book->narrators->pluck('name')->implode(', '),
                'book.series' => $seriesText,
                'book.duration' => $durationFormatted,
                'book.description' => Str::limit($book->description ?? '', 200),
                'cover' => $coverUrl,
                'available_books' => $availableBooks,
                'current_book_id' => $book->id
            ]);
        } catch (\Exception $e) {
            Log::error('Sample data error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to load sample data: ' . $e->getMessage()], 500);
        }
    }
}
