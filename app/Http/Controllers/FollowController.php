<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\Series;
use App\Models\Follow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FollowController extends Controller
{
    public function follow(Request $request, $followableType, $followableId)
    {
       //Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);

        //Check type
        if($request->followable_type == 'author'){
            $followable = Author::findOrFail($request->followable_id);
        } else {
            $followable = Series::findOrFail($request->followable_id);
        }

        Follow::create([
            'user_id' => Auth::id(),
            'followable_type' => $followableType,
            'followable_id' => $followableId,
        ]);

        return back()->with('success', 'Successfully followed!');
    }

    public function unfollow(Request $request, $followableType, $followableId)
    {
         //Validate input
        $request->validate([
            'followable_type' => 'required|in:author,series',
            'followable_id' => 'required|integer',
        ]);
        //Check type
        if($request->followable_type == 'author'){
            $followable = Author::findOrFail($request->followable_id);
        } else {
            $followable = Series::findOrFail($request->followable_id);
        }


        Follow::where([
            'user_id' => Auth::id(),
            'followable_type' => $followableType,
            'followable_id' => $followableId,
        ])->delete();

        return back()->with('success', 'Successfully unfollowed!');
    }
}
