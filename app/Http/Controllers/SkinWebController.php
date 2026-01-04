<?php

namespace App\Http\Controllers;

use App\Services\Contracts\SkinServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
            $result = $this->skinService->listSkins($filters, $page, 24, $sort);

            return view('gallery.skins.index', [
                'skins' => $result['data'],
                'pagination' => $result['pagination'],
                'search' => $search,
                'sort' => $sort,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list skins: ' . $e->getMessage());

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

            if ($skin['user_id'] !== Auth::id()) {
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
}
