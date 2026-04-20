<?php

namespace App\Http\Controllers;

use App\Models\KanbanTask;
use App\Models\KanbanTaskAttachment;
use Illuminate\Http\Request;

class BugReportController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'browser'     => 'nullable|string|max:255',
            'user_name'   => 'nullable|string|max:255',
            'type'        => 'nullable|string|in:bug,feature,improvement,ux,cosmetic,unexpected',
            'files.*'     => 'nullable|file|max:20480',
        ]);

        $maxOrder = KanbanTask::where('status', 'backlog')->max('order');

        $task = KanbanTask::create([
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status'      => 'backlog',
            'type'        => $validated['type'] ?? 'bug',
            'browser'     => $validated['browser'] ?? null,
            'user_name'   => $validated['user_name'] ?? null,
            'page_url'    => $request->headers->get('referer'),
            'order'       => $maxOrder !== null ? $maxOrder + 1 : 0,
        ]);

        if ($request->hasFile('files')) {
            foreach ($request->file('files') as $file) {
                $path = $file->store("kanban-attachments/{$task->id}", 'public');
                KanbanTaskAttachment::create([
                    'kanban_task_id' => $task->id,
                    'original_name'  => $file->getClientOriginalName(),
                    'path'           => $path,
                    'mime_type'      => $file->getMimeType(),
                    'size'           => $file->getSize(),
                ]);
            }
        }

        return response()->json(['success' => true]);
    }
}
