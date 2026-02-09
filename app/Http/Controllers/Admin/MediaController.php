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
        $search = $request->input('search');
        $type = $request->input('type');
        $collection = $request->input('collection');
        $modelType = $request->input('model_type');
        $perPage = $request->input('per_page', 30);

        if ($search) {
            // Поиск через Meilisearch с фильтрами
            $meilisearchFilters = $this->buildMeilisearchFilters($type, $collection, $modelType);

            $mediaQuery = Media::search($search);

            if ($meilisearchFilters) {
                $mediaQuery->options(['filter' => $meilisearchFilters]);
            }

            // Получить ID из Meilisearch, затем загрузить с Eloquent
            $media = $mediaQuery->query(function ($query) {
                $query->with('model');
            })->paginate($perPage)->withQueryString();
        } else {
            // Без поиска — обычный Eloquent запрос с фильтрами
            $query = Media::query()->with('model');

            if ($type) {
                match ($type) {
                    'images' => $query->where('mime_type', 'like', 'image/%'),
                    'videos' => $query->where('mime_type', 'like', 'video/%'),
                    'documents' => $query->where('mime_type', 'like', 'application/%'),
                    default => null,
                };
            }

            if ($collection) {
                $query->where('collection_name', $collection);
            }

            if ($modelType) {
                $query->where('model_type', $modelType);
            }

            $query->orderByDesc('id');

            $media = $query->paginate($perPage)->withQueryString();
        }

        // Добавить URL для каждого медиафайла
        $media->getCollection()->transform(function ($item) {
            $item->thumbnail_url = $item->hasGeneratedConversion('thumb')
                ? $item->getUrl('thumb')
                : $item->getUrl();

            // Название модели для отображения
            if ($item->model) {
                $item->owner_display_name = $item->model->title ?? $item->model->name ?? null;
            } else {
                $item->owner_display_name = null;
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

    /**
     * Построить строку фильтров для Meilisearch.
     */
    private function buildMeilisearchFilters(?string $type, ?string $collection, ?string $modelType): string
    {
        $filters = [];

        if ($type) {
            $filters[] = 'mime_type_group = "' . addslashes($type) . '"';
        }

        if ($collection) {
            $filters[] = 'collection_name = "' . addslashes($collection) . '"';
        }

        if ($modelType) {
            $filters[] = 'model_type = "' . addslashes($modelType) . '"';
        }

        return implode(' AND ', $filters);
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
