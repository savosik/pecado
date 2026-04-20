<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KanbanTask;
use Illuminate\Http\Request;

class KanbanCommentController extends Controller
{
    public function store(Request $request, KanbanTask $kanbanTask)
    {
        $validated = $request->validate([
            'content' => 'required|string',
            'parent_id' => 'nullable|exists:kanban_comments,id',
        ]);

        $kanbanTask->allComments()->create([
            'content' => $validated['content'],
            'parent_id' => $validated['parent_id'],
        ]);

        return redirect()->back();
    }
}
