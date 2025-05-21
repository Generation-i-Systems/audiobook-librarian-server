<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Services\FirestoreService;

class ReviewController extends Controller
{
    public function store(Request $request, $bookId)
    {
        $request->validate([
            'comment' => 'required|string',
            'age_rating' => 'required|integer|min:1|max:5',
            'content_rating' => 'required|string',
        ]);

        $firestore = new FirestoreService();
        $firestore->createReview([
            'book_id' => $bookId,
            'user_id' => Auth::id(),
            'comment' => $request->comment,
            'age_rating' => $request->age_rating,
            'content_rating' => $request->content_rating,
        ]);

        return back()->with('success', 'Review added successfully!');
    }

    public function destroy(Review $review)
    {
        // Authorization logic:  Only allow the review's author to delete it.
        if (Auth::id() !== $review->user_id) {
            abort(403, 'Unauthorized action.');
        }

        $review->delete();

        return back()->with('success', 'Review deleted successfully!');
    }
}
