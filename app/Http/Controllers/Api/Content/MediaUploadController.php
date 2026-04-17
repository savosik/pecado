<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Загрузка медиафайлов.
 *
 * Эндпоинт для загрузки статичных файлов (изображений, видео),
 * которые используются в контенте (статьи, новости и т.д.).
 * Файлы сохраняются в S3/MinIO и доступны по публичному URL.
 *
 * @tags Медиафайлы
 */
class MediaUploadController extends Controller
{
    /**
     * Загрузить медиафайл.
     *
     * Принимает файл (изображение или видео), сохраняет его в S3-хранилище
     * и возвращает публичный URL для использования в HTML-контенте
     * (например, `<img src="...">`).
     *
     * Допустимые форматы:
     * - Изображения: jpeg, png, jpg, webp, gif, svg
     * - Видео: mp4, webm, mov
     *
     * Максимальный размер файла: 20 МБ.
     *
     * @response 201 { "url": "https://s3.example.com/content-uploads/abc123.jpg", "file_name": "photo.jpg", "mime_type": "image/jpeg", "size": 102400 }
     * @response 422 { "message": "Поле файл обязательно для заполнения.", "errors": { "file": ["Поле файл обязательно для заполнения."] } }
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpeg,png,jpg,webp,gif,svg,mp4,webm,mov',
                'max:20480', // 20 МБ
            ],
        ], [
            'file.required' => 'Файл обязателен для загрузки.',
            'file.file' => 'Переданное значение должно быть файлом.',
            'file.mimes' => 'Допустимые форматы: jpeg, png, jpg, webp, gif, svg, mp4, webm, mov.',
            'file.max' => 'Максимальный размер файла — 20 МБ.',
        ]);

        $file = $request->file('file');

        $extension = $file->getClientOriginalExtension();
        $filename = Str::ulid().'.'.$extension;

        $path = $file->storeAs('content-uploads', $filename, 's3');

        $url = Storage::disk('s3')->url($path);

        return response()->json([
            'url' => $url,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ], 201);
    }
}
