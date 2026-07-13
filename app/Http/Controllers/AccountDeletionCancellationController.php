<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AccountDeletionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class AccountDeletionCancellationController extends Controller
{
    public function show(string $token): View
    {
        return view('account-deletion.cancel', ['token' => $token]);
    }

    public function cancel(string $token, AccountDeletionService $accountDeletionService): RedirectResponse
    {
        if (!$accountDeletionService->cancel($token)) {
            return redirect('/account-deletion/cancelled')->with('error', 'This cancellation link is invalid or has expired.');
        }

        return redirect('/account-deletion/cancelled')->with('status', 'Your account deletion has been cancelled.');
    }
}
