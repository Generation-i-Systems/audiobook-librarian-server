<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function __construct(private readonly DocumentStoreServiceInterface $documentStoreService)
    {
    }

    /**
     * Switch the UI language for this browser session and, when signed in, remember it.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys((array) config('locales.supported', [])))],
        ]);

        $request->session()->put('locale', $validated['locale']);

        $user = $request->user();
        if ($user !== null) {
            $this->documentStoreService->updateUser((string) $user->id, ['locale' => $validated['locale']]);
        }

        return back();
    }
}
