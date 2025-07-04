<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ProfileController extends Controller
{
    protected DocumentStoreServiceInterface $documentStoreService;

    public function __construct(DocumentStoreServiceInterface $documentStoreService)
    {
        $this->documentStoreService = $documentStoreService;
    }
    public function index()
    {
        $firestore = $this->documentStoreService;
        $userId = Auth::id();

        // Fix: Use getCollection method instead of calling collection() directly on the client
        $usersCollection = $firestore->getCollection('users');
        $userDoc = $usersCollection->findOne(['_id' => $userId]);

        $user = $userDoc ? $this->normalizeMongoDocument($userDoc) : null;
        if ($user) {
            $user['id'] = $userId;
        }

        return view('profile.index', compact('user'));
    }

    public function update(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . Auth::id(),
        ]);

        $firestore = $this->documentStoreService;
        $userId = Auth::id();

        // Fix: Use getCollection method instead of calling collection() directly on the client
        $usersCollection = $firestore->getCollection('users');
        $usersCollection->updateOne(
            ['_id' => $userId],
            ['$set' => [
                'name' => $request->name,
                'email' => $request->email,
            ]]
        );

        return back()->with('success', 'Profile updated successfully!');
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $firestore = $this->documentStoreService;
        $userId = Auth::id();

        // Fix: Use getCollection method instead of calling collection() directly on the client
        $usersCollection = $firestore->getCollection('users');
        $userDoc = $usersCollection->findOne(['_id' => $userId]);

        $user = $userDoc ? $this->normalizeMongoDocument($userDoc) : null;
        if (!$user || !Hash::check($request->current_password, $user['password'])) {
            return back()->withErrors(['current_password' => 'Incorrect current password.']);
        }

        $usersCollection->updateOne(
            ['_id' => $userId],
            ['$set' => ['password' => Hash::make($request->password)]]
        );

        return back()->with('success', 'Password changed successfully!');
    }

    public function requestAdminPermissions(Request $request)
    {
        $request->validate([
            'content' => 'required|string',
        ]);

        $firestore = $this->documentStoreService;
        $userId = Auth::id();

        // Fix: Use getCollection method instead of calling collection() directly on the client
        $messagesCollection = $firestore->getCollection('messages');
        $messagesCollection->insertOne([
            'user_id' => $userId,
            'content' => $request->input('content'),
            'is_from_admin' => false,
        ]);

        return back()->with('success', 'Admin permission request sent!');
    }

    /**
     * Normalize MongoDB document to PHP array
     *
     * @param mixed $document
     * @return array
     */
    protected function normalizeMongoDocument($document): array
    {
        if ($document instanceof \MongoDB\Model\BSONDocument) {
            $document = (array) $document;
        }

        // Convert any nested BSONDocument or BSONArray objects to PHP arrays
        foreach ($document as $key => $value) {
            if ($value instanceof \MongoDB\Model\BSONDocument || $value instanceof \MongoDB\Model\BSONArray) {
                $document[$key] = $this->normalizeMongoDocument($value);
            } elseif (is_array($value)) {
                $document[$key] = array_map(function ($item) {
                    return ($item instanceof \MongoDB\Model\BSONDocument || $item instanceof \MongoDB\Model\BSONArray)
                        ? $this->normalizeMongoDocument($item)
                        : $item;
                }, $value);
            }
        }

        return $document;
    }
}
