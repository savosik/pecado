<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Контроллер загрузки изображений для Editor.js.
 *
 * Два эндпоинта:
 * - POST /admin/api/upload-image — загрузка файла
 * - POST /admin/api/fetch-url   — загрузка по URL
 *
 * Формат ответа: { success: 1, file: { url: "..." } }
 */
class EditorUploadController extends Controller
{
    /**
     * Загрузить изображение файлом.
     */
    public function uploadByFile(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|image|max:5120', // 5 MB
        ]);

        $file = $request->file('image');
        $path = $file->store('editor-images/' . date('Y/m'), 's3');

        return response()->json([
            'success' => 1,
            'file' => [
                'url' => Storage::disk('s3')->url($path),
            ],
        ]);
    }

    /**
     * Загрузить изображение по URL.
     */
    public function fetchByUrl(Request $request): JsonResponse
    {
        $request->validate([
            'url' => 'required|url',
        ]);

        $url = $request->input('url');

        // Для внешних URL — просто возвращаем как есть
        return response()->json([
            'success' => 1,
            'file' => [
                'url' => $url,
            ],
        ]);
    }
}
