<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Book;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{

    public function store(Request $request, Book $book)
    {
        $request->validate([
            'comment' => 'required|string',
            'age_rating' => 'required|integer|min:1|max:5',
            'content_rating' => 'required|string',
        ]);

        Review::create([
            'book_id' => $book->id,
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
