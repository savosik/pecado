<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\KanbanTask;
use App\Models\KanbanTaskAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class KanbanAttachmentController extends Controller
{
    public function store(Request $request, KanbanTask $kanbanTask)
    {
        $request->validate([
            'files'   => 'required|array|max:20',
            'files.*' => 'file|max:20480',
        ]);

        foreach ($request->file('files') as $file) {
            $path = $file->store("kanban-attachments/{$kanbanTask->id}", 'public');

            $kanbanTask->attachments()->create([
                'original_name' => $file->getClientOriginalName(),
                'path'          => $path,
                'mime_type'     => $file->getMimeType(),
                'size'          => $file->getSize(),
            ]);
        }

        return redirect()->back();
    }

    public function destroy(KanbanTaskAttachment $attachment)
    {
        Storage::disk('public')->delete($attachment->path);
        $attachment->delete();

        return redirect()->back();
    }
}
