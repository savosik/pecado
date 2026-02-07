<?php

namespace App\Http\Controllers\Admin;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class MediaController extends Controller
{
    public function index(Request $request)
    {
        $query = Media::query()->with('model');

        // Поиск по имени и файлу
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('file_name', 'like', "%{$search}%");
            });
        }

        // Фильтр по типу файла
        if ($type = $request->input('type')) {
            match ($type) {
                'images' => $query->where('mime_type', 'like', 'image/%'),
                'videos' => $query->where('mime_type', 'like', 'video/%'),
                'documents' => $query->where('mime_type', 'like', 'application/%'),
                default => null,
            };
        }

        // Фильтр по коллекции
        if ($collection = $request->input('collection')) {
            $query->where('collection_name', $collection);
        }

        // Фильтр по модели
        if ($modelType = $request->input('model_type')) {
            $query->where('model_type', $modelType);
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Пагинация
        $perPage = $request->input('per_page', 30);
        $media = $query->paginate($perPage)->withQueryString();

        // Добавить URL для каждого медиафайла
        $media->getCollection()->transform(function ($item) {
            $item->url = $item->getUrl();
            $item->preview_url = $item->hasGeneratedConversion('thumb') 
                ? $item->getUrl('thumb') 
                : $item->getUrl();
            
            // Название модели для отображения
            if ($item->model) {
                $item->model_name = $item->model->title ?? $item->model->name ?? null;
            } else {
                $item->model_name = null;
            }
            
            return $item;
        });

        // Получить уникальные коллекции для фильтра
        $collections = Media::query()
            ->select('collection_name')
            ->distinct()
            ->pluck('collection_name')
            ->filter()
            ->values();

        // Получить уникальные типы моделей для фильтра
        $modelTypes = Media::query()
            ->select('model_type')
            ->distinct()
            ->pluck('model_type')
            ->filter()
            ->values();

        return Inertia::render('Admin/Pages/Media/Index', [
            'media' => $media,
            'filters' => $request->only(['search', 'type', 'collection', 'model_type', 'sort_by', 'sort_order', 'per_page']),
            'collections' => $collections,
            'modelTypes' => $modelTypes,
        ]);
    }

    public function destroy(Media $media)
    {
        // Удалить медиафайл (Spatie автоматически удалит файл из хранилища)
        $media->delete();

        return redirect()
            ->route('admin.media.index')
            ->with('success', 'Медиафайл успешно удален');
    }
}
