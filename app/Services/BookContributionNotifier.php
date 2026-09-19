<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\BookContributionSubmittedMail;
use App\Models\BookContribution;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class BookContributionNotifier
{
    public function notifyAdmins(BookContribution $contribution): void
    {
        $contribution->loadMissing(['book:id,title', 'submitter:id,name,email']);

        User::query()
            ->whereIn('role', ['admin', 'super-admin'])
            ->whereNotNull('email')
            ->eachById(function (User $admin) use ($contribution): void {
                try {
                    Mail::to($admin)->send(new BookContributionSubmittedMail($contribution));
                } catch (Throwable $exception) {
                    Log::error('Unable to email admin about book contribution', [
                        'contribution_id' => $contribution->id,
                        'admin_id' => $admin->id,
                        'exception' => $exception,
                    ]);
                }
            });
    }
}
