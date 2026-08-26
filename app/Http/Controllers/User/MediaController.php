<?php

namespace App\Http\Controllers\User;

use App\Models\Article;
use App\Models\Brand;
use App\Models\BrandStory;
use App\Models\Media;
use App\Models\News;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Story;
use App\Models\StorySlide;
use App\Support\Search\FuzzyProductMatcher;
use App\Support\Search\QueryRouter;
use Illuminate\Contracts\Database\Eloquent\Builder;
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
     * Белый список владельцев медиа, доступных партнёру.
     *
     * Только публичный контент витрины. Всё остальное (голосовые заметки о
     * клиентах на User, фото уценки ProductDefect, вложения контактов и
     * вопросов, фото менеджеров) — служебные данные, в кабинет не попадают.
     * Любой новый тип добавляется сюда осознанно, по умолчанию — закрыт.
     */
    public const PUBLIC_MODEL_TYPES = [
        Product::class,
        Brand::class,
        Article::class,
        News::class,
        BrandStory::class,
        Story::class,
        StorySlide::class,
        Promotion::class,
        \App\Models\Banner::class,
        \App\Models\Category::class,
        \App\Models\ProductSelection::class,
        \App\Models\Certificate::class,
    ];

    /**
     * Базовый запрос медиатеки кабинета — всегда сужен до публичных владельцев.
     */
    private function publicMedia(): \Illuminate\Database\Eloquent\Builder
    {
        return Media::query()->whereIn('model_type', self::PUBLIC_MODEL_TYPES);
    }

    /**
     * Страница медиатеки (Inertia).
     */
    public function index(): Response
    {
        // Уникальные коллекции для фильтра
        $collections = $this->publicMedia()
            ->select('collection_name')
            ->distinct()
            ->pluck('collection_name')
            ->filter()
            ->values();

        // Уникальные типы моделей для фильтра
        $modelTypes = $this->publicMedia()
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

        $query = $this->publicMedia()->with('model');

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

        $this->applyDateRange($query, $request);
        $this->applySizeRange($query, $request);

        if ($search) {
            // Подстрочный поиск: предсказуемое поведение — "550001" не тянет
            // "5500100". Meilisearch с его typo/prefix-толерантностью для
            // артикулов вредил, поэтому возвращаемся к LIKE по полям медиа
            // и связанным моделям (товар, бренд, статьи, новости и т.д.).
            $this->applySearchFilter($query, $search, $modelType);
        }

        $this->applySorting($query, $sort);

        $media = $query->paginate($perPage)->withQueryString();

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
        if (! in_array($media->model_type, self::PUBLIC_MODEL_TYPES, true)) {
            abort(404, 'Файл не найден');
        }

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

        $mediaItems = $this->publicMedia()->whereIn('id', $ids)->get();

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
     * Карта searchable-полей связанной модели для whereHasMorph.
     *
     * Каждый тип содержит два набора полей:
     *  - exact: точное равенство (артикулы, коды, штрихкоды — идентификаторы,
     *    где подстрочный поиск "550001" не должен цеплять "5500100").
     *  - like: LIKE '%q%' (свободный текст: названия, заголовки).
     * Плюс relations — связи, по которым тоже надо искать (brand.name, barcodes.barcode).
     */
    private function morphSearchMap(): array
    {
        return [
            Product::class => [
                'exact' => ['code', 'sku', 'barcode', 'external_id'],
                'like' => ['name'],
                'relations' => [
                    ['relation' => 'brand', 'field' => 'name', 'match' => 'like'],
                    ['relation' => 'barcodes', 'field' => 'barcode', 'match' => 'exact'],
                ],
            ],
            Brand::class => ['exact' => [], 'like' => ['name'], 'relations' => []],
            Article::class => ['exact' => [], 'like' => ['title'], 'relations' => []],
            News::class => ['exact' => [], 'like' => ['title'], 'relations' => []],
            BrandStory::class => ['exact' => [], 'like' => ['title'], 'relations' => []],
            StorySlide::class => ['exact' => [], 'like' => ['title'], 'relations' => []],
            Story::class => ['exact' => [], 'like' => ['name'], 'relations' => []],
            Promotion::class => ['exact' => [], 'like' => ['name'], 'relations' => []],
        ];
    }

    /**
     * Наложить поисковый фильтр на запрос медиа.
     *
     * Ищет:
     *  - LIKE в name/file_name самого медиа;
     *  - по связанной модели через whereHasMorph — exact для идентификаторов
     *    (sku, штрихкод и т.п.) и LIKE для текста.
     *
     * Если задан фильтр "Тип сущности" — морф сужается до одного класса,
     * SQL упрощается и работает быстрее.
     */
    private function applySearchFilter($query, string $search, ?string $modelType): void
    {
        $needle = '%'.$this->escapeLike($search).'%';
        $morphMap = $this->morphSearchMap();

        if ($modelType && isset($morphMap[$modelType])) {
            $morphMap = [$modelType => $morphMap[$modelType]];
        }

        $queryType = QueryRouter::classify($search);
        $fuzzyProductIds = FuzzyProductMatcher::isApplicable($search, $queryType)
            ? FuzzyProductMatcher::findProductIds($search)
            : [];

        $query->where(function ($q) use ($needle, $search, $morphMap, $fuzzyProductIds) {
            $q->where('name', 'like', $needle)
                ->orWhere('file_name', 'like', $needle);

            foreach ($morphMap as $class => $fields) {
                $q->orWhereHasMorph('model', [$class], function (Builder $b) use ($fields, $needle, $search, $class, $fuzzyProductIds) {
                    $b->where(function (Builder $b) use ($fields, $needle, $search, $class, $fuzzyProductIds) {
                        foreach ($fields['exact'] as $column) {
                            $b->orWhere($column, '=', $search);
                        }
                        foreach ($fields['like'] as $column) {
                            $b->orWhere($column, 'like', $needle);
                        }
                        foreach ($fields['relations'] as $rel) {
                            $b->orWhereHas($rel['relation'], fn (Builder $r) => $rel['match'] === 'exact'
                                ? $r->where($rel['field'], '=', $search)
                                : $r->where($rel['field'], 'like', $needle));
                        }
                        if ($class === Product::class && ! empty($fuzzyProductIds)) {
                            $b->orWhereIn('id', $fuzzyProductIds);
                        }
                    });
                });
            }
        });
    }

    /**
     * Фильтр по диапазону даты загрузки (C-8.2). `date_from`/`date_to` — `Y-m-d`.
     */
    private function applyDateRange($query, Request $request): void
    {
        $from = trim((string) $request->input('date_from', ''));
        $to = trim((string) $request->input('date_to', ''));

        if ($from !== '') {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to !== '') {
            $query->whereDate('created_at', '<=', $to);
        }
    }

    /**
     * Фильтр по размеру файла в мегабайтах (C-8.3). На клиенте поля в МБ
     * для UX-удобства, в БД хранится `size` в байтах — конвертация здесь.
     */
    private function applySizeRange($query, Request $request): void
    {
        $from = $request->input('size_from_mb');
        $to = $request->input('size_to_mb');

        if (is_numeric($from)) {
            $query->where('size', '>=', (int) round(((float) $from) * 1048576));
        }
        if (is_numeric($to)) {
            $query->where('size', '<=', (int) round(((float) $to) * 1048576));
        }
    }

    /**
     * Экранировать спецсимволы LIKE, чтобы "50%" не матчил что попало.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
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
