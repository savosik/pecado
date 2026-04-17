<?php

namespace App\Http\Controllers\User;

use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use ZipArchive;

class MediaController extends Controller
{
    /**
     * Страница медиатеки (Inertia).
     */
    public function index(): Response
    {
        // Уникальные коллекции для фильтра
        $collections = Media::query()
            ->select('collection_name')
            ->distinct()
            ->pluck('collection_name')
            ->filter()
            ->values();

        // Уникальные типы моделей для фильтра
        $modelTypes = Media::query()
            ->select('model_type')
            ->distinct()
            ->pluck('model_type')
            ->filter()
            ->values();

        return Inertia::render('User/Cabinet/Media/Index', [
            'collections' => $collections,
            'modelTypes' => $modelTypes,
        ]);
    }

    /**
     * JSON API — список медиа с фильтрацией, поиском, пагинацией.
     */
    public function api(Request $request): JsonResponse
    {
        $search = $request->input('search');
        $type = $request->input('type');
        $collection = $request->input('collection');
        $modelType = $request->input('model_type');
        $sort = $request->input('sort', 'newest');
        $perPage = $request->integer('per_page', 24);

        if ($search) {
            // Поиск через Meilisearch с фильтрами
            $meilisearchFilters = $this->buildMeilisearchFilters($type, $collection, $modelType);

            $mediaQuery = Media::search($search);

            if ($meilisearchFilters) {
                $mediaQuery->options(['filter' => $meilisearchFilters]);
            }

            $media = $mediaQuery->query(function ($query) use ($sort) {
                $query->with('model');
                $this->applySorting($query, $sort);
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

            $this->applySorting($query, $sort);

            $media = $query->paginate($perPage)->withQueryString();
        }

        // Преобразовать каждый медиафайл для фронтенда
        $media->getCollection()->transform(function ($item) {
            $item->thumbnail_url = $item->hasGeneratedConversion('thumb')
                ? $item->getUrl('thumb')
                : $item->getUrl();

            $item->download_url = route('cabinet.media.download', $item->id);

            // Название модели для отображения
            if ($item->model) {
                $item->owner_display_name = $item->model->title ?? $item->model->name ?? null;
            } else {
                $item->owner_display_name = null;
            }

            $item->model_type_label = $this->getModelTypeLabel($item->model_type);

            return $item;
        });

        return response()->json($media);
    }

    /**
     * Скачать один медиафайл.
     */
    public function download(Media $media)
    {
        $disk = $media->disk;
        $path = $media->getPath();

        if (! Storage::disk($disk)->exists($path)) {
            abort(404, 'Файл не найден');
        }

        return Storage::disk($disk)->download($path, $media->file_name);
    }

    /**
     * Скачать несколько медиафайлов как ZIP-архив.
     */
    public function downloadBatch(Request $request): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $ids = $request->input('ids', []);

        if (empty($ids) || ! is_array($ids)) {
            return response()->json(['message' => 'Не указаны файлы для скачивания'], 422);
        }

        $mediaItems = Media::whereIn('id', $ids)->get();

        if ($mediaItems->isEmpty()) {
            return response()->json(['message' => 'Файлы не найдены'], 404);
        }

        $zipFileName = 'media-'.now()->format('Y-m-d-His').'.zip';
        $zipPath = storage_path('app/temp/'.$zipFileName);

        // Убедиться, что директория temp существует
        if (! file_exists(storage_path('app/temp'))) {
            mkdir(storage_path('app/temp'), 0755, true);
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return response()->json(['message' => 'Не удалось создать zip-архив'], 500);
        }

        foreach ($mediaItems as $media) {
            $disk = $media->disk;
            $path = $media->getPath();

            if (Storage::disk($disk)->exists($path)) {
                $fileContent = Storage::disk($disk)->get($path);
                // Префикс ID, чтобы избежать конфликтов имён
                $zip->addFromString($media->id.'-'.$media->file_name, $fileContent);
            }
        }

        $zip->close();

        return response()->download($zipPath, $zipFileName)->deleteFileAfterSend(true);
    }

    /**
     * Построить строку фильтров для Meilisearch.
     */
    private function buildMeilisearchFilters(?string $type, ?string $collection, ?string $modelType): string
    {
        $filters = [];

        if ($type) {
            $filters[] = 'mime_type_group = "'.addslashes($type).'"';
        }

        if ($collection) {
            $filters[] = 'collection_name = "'.addslashes($collection).'"';
        }

        if ($modelType) {
            $filters[] = 'model_type = "'.addslashes($modelType).'"';
        }

        return implode(' AND ', $filters);
    }

    /**
     * Применить сортировку к запросу.
     */
    private function applySorting($query, string $sort): void
    {
        match ($sort) {
            'oldest' => $query->orderBy('created_at', 'asc'),
            'size_asc' => $query->orderBy('size', 'asc'),
            'size_desc' => $query->orderBy('size', 'desc'),
            'name_asc' => $query->orderBy('file_name', 'asc'),
            'name_desc' => $query->orderBy('file_name', 'desc'),
            default => $query->orderBy('created_at', 'desc'), // newest
        };
    }

    /**
     * Получить читаемое название типа модели.
     */
    private function getModelTypeLabel(?string $modelType): ?string
    {
        if (! $modelType) {
            return null;
        }

        $typeMap = [
            'App\\Models\\Product' => 'Товар',
            'App\\Models\\Article' => 'Статья',
            'App\\Models\\Banner' => 'Баннер',
            'App\\Models\\Page' => 'Страница',
            'App\\Models\\News' => 'Новость',
            'App\\Models\\Category' => 'Категория',
            'App\\Models\\Promotion' => 'Акция',
            'App\\Models\\Brand' => 'Бренд',
            'App\\Models\\BrandStory' => 'История бренда',
            'App\\Models\\Story' => 'Сторис',
            'App\\Models\\StorySlide' => 'Слайд сторис',
            'App\\Models\\Certificate' => 'Сертификат',
            'App\\Models\\ProductSelection' => 'Подборка',
            'App\\Models\\User' => 'Пользователь',
        ];

        return $typeMap[$modelType] ?? class_basename($modelType);
    }
}
