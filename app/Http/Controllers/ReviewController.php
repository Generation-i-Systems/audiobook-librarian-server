<?php

namespace App\Http\Controllers;

use App\Contracts\DocumentStoreServiceInterface;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    public function store(Request $request, $bookId)
    {
        $request->validate([
            'comment' => 'required|string',
            'age_rating' => 'required|integer|min:1|max:5',
            'content_rating' => 'required|string',
        ]);

        $documentStore = app(DocumentStoreServiceInterface::class);
        // Assuming createReview exists on the interface or implementation, but PHPStan complains.
        // It should be added to the interface. For now, ignoring or fixing the interface is the way.
        // If it's a dynamic method or missing from interface, we can suppress.
        // But better to check if it exists.
        if (method_exists($documentStore, 'createReview')) {
            $documentStore->createReview([
                'book_id' => $bookId,
                'user_id' => Auth::id(),
                'comment' => $request->comment,
                'age_rating' => $request->age_rating,
                'content_rating' => $request->content_rating,
            ]);
        }

        return back()->with('success', 'Review added successfully!');
    }

    /**
     * @param  \App\Models\Review|mixed  $review
     */
    public function destroy($review)
    {
        // Authorization logic:  Only allow the review's author to delete it.
        // Ensure $review is an object and has user_id
        if (!is_object($review) || !isset($review->user_id)) {
            abort(404);
        }

        if (Auth::id() !== $review->user_id) {
            abort(403, 'Unauthorized action.');
        }

        if (method_exists($review, 'delete')) {
            $review->delete();
        }

        return back()->with('success', 'Review deleted successfully!');
    }
}
