<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KanbanTask;
use Illuminate\Http\Request;
use Inertia\Inertia;

class KanbanController extends Controller
{
    public function index()
    {
        $tasks = KanbanTask::with(['comments.replies.replies'])->orderBy('order')->get();
        
        $columns = [
            'todo',
            'in_progress',
            'testing',
            'done',
            'reopen'
        ];

        return Inertia::render('Admin/Pages/Kanban/Index', [
            'tasks' => $tasks,
            'columns' => $columns
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'status' => 'required|string|in:todo,in_progress,testing,done,reopen',
            'page_url' => 'nullable|url|max:255',
            'browser' => 'nullable|string|max:255',
            'user_name' => 'nullable|string|max:255',
        ]);

        $maxOrder = KanbanTask::where('status', $validated['status'])->max('order');
        $validated['order'] = $maxOrder !== null ? $maxOrder + 1 : 0;

        KanbanTask::create($validated);

        return redirect()->back();
    }

    public function update(Request $request, KanbanTask $kanbanTask)
    {
        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'status' => 'sometimes|string|in:todo,in_progress,testing,done,reopen',
            'page_url' => 'nullable|string|max:255',
            'browser' => 'nullable|string|max:255',
            'user_name' => 'nullable|string|max:255',
        ]);

        $kanbanTask->update($validated);

        return redirect()->back();
    }

    public function updateOrder(Request $request, KanbanTask $kanbanTask)
    {
        $validated = $request->validate([
            'status' => 'required|string|in:todo,in_progress,testing,done,reopen',
            'order' => 'required|integer',
        ]);

        if ($kanbanTask->status !== $validated['status']) {
            KanbanTask::where('status', $kanbanTask->status)
                ->where('order', '>', $kanbanTask->order)
                ->decrement('order');

            KanbanTask::where('status', $validated['status'])
                ->where('order', '>=', $validated['order'])
                ->increment('order');
        } else {
            if ($kanbanTask->order < $validated['order']) {
                KanbanTask::where('status', $kanbanTask->status)
                    ->whereBetween('order', [$kanbanTask->order + 1, $validated['order']])
                    ->decrement('order');
            } elseif ($kanbanTask->order > $validated['order']) {
                KanbanTask::where('status', $kanbanTask->status)
                    ->whereBetween('order', [$validated['order'], $kanbanTask->order - 1])
                    ->increment('order');
            }
        }

        $kanbanTask->update([
            'status' => $validated['status'],
            'order' => $validated['order'],
        ]);

        return redirect()->back();
    }
}
