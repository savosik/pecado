<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Request;
use Spatie\Tags\Tag;

class TagController extends AdminController
{
    public function search(Request $request)
    {
        $query = $request->input('query');

        $tags = Tag::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%");
            })
            ->take(10)
            ->get()
            ->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                ];
            });

        return response()->json($tags);
    }
}
