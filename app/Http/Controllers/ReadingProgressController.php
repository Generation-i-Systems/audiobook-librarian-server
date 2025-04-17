<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ReadingProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReadingProgressController extends Controller
{
    public function update(Request $request, Book $book)
    {
        $request->validate([
            'current_position' => 'required|integer|min:0',
        ]);

        $user = Auth::user();

        $progress = ReadingProgress::updateOrCreate(
            ['user_id' => $user->id, 'book_id' => $book->id],
            ['current_position' => $request->current_position]
        );

        return response()->json(['success' => true]);
    }

    public function get(Book $book)
    {
        $user = Auth::user();

        $progress = ReadingProgress::where('user_id', $user->id)
            ->where('book_id', $book->id)
            ->first();

        if ($progress) {
            return response()->json(['current_position' => $progress->current_position]);
        } else {
            return response()->json(['current_position' => 0]);
        }
    }
}
