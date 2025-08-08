<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentStoreServiceInterface;
use App\Http\Controllers\Controller;

class AccountRequestController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }

    public function index()
    {
        $requests = $this->documentStoreService->getPendingAccountRequests();

        return view('admin.account_requests.index', ['accountRequests' => $requests]);
    }

    public function update($id)
    {
        $accountRequest = $this->documentStoreService->getAccountRequest($id);

        if (!$accountRequest) {
            return back()->withErrors(['error' => 'Account request not found.']);
        }

        $success = $this->documentStoreService->approveAccountRequest($id);

        if ($success) {
            return back()->with('success', 'Account request approved!');
        } else {
            return back()->withErrors(['error' => 'Failed to approve account request.']);
        }
    }

    public function destroy($id)
    {
        $success = $this->documentStoreService->rejectAccountRequest($id);

        if ($success) {
            return back()->with('success', 'Account request rejected!');
        } else {
            return back()->withErrors(['error' => 'Failed to reject account request.']);
        }
    }
}
