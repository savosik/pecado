<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Изображения товаров каталога (запись).
 *
 * Позволяет контент-менеджеру загружать основное (`main`) и дополнительные
 * (`additional`) изображения к существующим товарам каталога — файлом
 * (multipart/form-data) или по URL. Остальные поля товара по-прежнему
 * доступны только на чтение (см. «Каталог — Товары»).
 *
 * @tags Каталог — Изображения товаров
 */
class ProductMediaController extends Controller
{
    /** Допустимые коллекции изображений товара. */
    private const COLLECTIONS = ['main', 'additional'];

    /**
     * Список изображений товара.
     *
     * Возвращает основное и дополнительные изображения товара
     * с публичными URL и превью.
     */
    public function index(Product $product): JsonResponse
    {
        return response()->json([
            'data' => [
                'main' => $this->collectionToArray($product, 'main'),
                'additional' => $this->collectionToArray($product, 'additional'),
            ],
        ]);
    }

    /**
     * Загрузить изображение(я) товара.
     *
     * Добавляет изображения в одну из коллекций товара:
     * - `main` — основное изображение (одно; новое заменяет предыдущее);
     * - `additional` — дополнительные изображения (добавляются к уже имеющимся).
     *
     * Источник изображения можно передать любым из способов (можно комбинировать
     * несколько для коллекции `additional`):
     * - `image` — один файл (multipart/form-data);
     * - `images[]` — несколько файлов;
     * - `image_url` — ссылка на изображение;
     * - `image_urls[]` — массив ссылок.
     *
     * Допустимые форматы: jpeg, png, jpg, webp, gif, svg. Максимум 10 МБ на файл.
     *
     * @param  Product  $product  ID товара
     *
     * @response 201 { "data": { "collection": "additional", "added": [ { "id": 10, "url": "https://s3.example.com/…/photo.jpg", "thumb_url": "…", "large_url": "…", "file_name": "photo.jpg", "collection": "additional", "order": 2 } ], "main": [], "additional": [] } }
     * @response 422 { "message": "Не передано ни одного изображения.", "errors": { "image": ["Не передано ни одного изображения."] } }
     */
    public function store(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'collection' => ['required', 'string', 'in:'.implode(',', self::COLLECTIONS)],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif,svg', 'max:10240'],
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,webp,gif,svg', 'max:10240'],
            'image_url' => ['nullable', 'url'],
            'image_urls' => ['nullable', 'array'],
            'image_urls.*' => ['url'],
        ], [
            'collection.required' => 'Укажите коллекцию: main или additional.',
            'collection.in' => 'Коллекция должна быть main (основное) или additional (дополнительные).',
            'image.image' => 'Файл должен быть изображением.',
            'image.mimes' => 'Допустимые форматы: jpeg, png, jpg, webp, gif, svg.',
            'image.max' => 'Максимальный размер файла — 10 МБ.',
            'images.*.image' => 'Каждый файл должен быть изображением.',
            'images.*.mimes' => 'Допустимые форматы: jpeg, png, jpg, webp, gif, svg.',
            'images.*.max' => 'Максимальный размер файла — 10 МБ.',
            'image_url.url' => 'Поле image_url должно быть корректной ссылкой.',
            'image_urls.*.url' => 'Каждый элемент image_urls должен быть корректной ссылкой.',
        ]);

        $collection = $validated['collection'];

        // Подсчёт переданных источников
        $fileImages = $request->hasFile('images') ? $request->file('images') : [];
        $urlImages = array_filter((array) ($validated['image_urls'] ?? []));
        $total = ($request->hasFile('image') ? 1 : 0)
            + count($fileImages)
            + ($request->filled('image_url') ? 1 : 0)
            + count($urlImages);

        if ($total === 0) {
            return response()->json([
                'message' => 'Не передано ни одного изображения.',
                'errors' => ['image' => ['Не передано ни одного изображения.']],
            ], 422);
        }

        if ($collection === 'main' && $total > 1) {
            return response()->json([
                'message' => 'Для основного изображения (main) допустимо только одно изображение.',
                'errors' => ['image' => ['Для основного изображения допустимо только одно изображение.']],
            ], 422);
        }

        $added = [];

        if ($request->hasFile('image')) {
            $added[] = $product->addMediaFromRequest('image')->toMediaCollection($collection);
        }

        foreach ($fileImages as $file) {
            $added[] = $product->addMedia($file)->toMediaCollection($collection);
        }

        if ($request->filled('image_url')) {
            $added[] = $product->addMediaFromUrl($validated['image_url'])->toMediaCollection($collection);
        }

        foreach ($urlImages as $url) {
            $added[] = $product->addMediaFromUrl($url)->toMediaCollection($collection);
        }

        $product->refresh();

        return response()->json([
            'data' => [
                'collection' => $collection,
                'added' => array_map(fn (Media $m) => $this->mediaToArray($m), $added),
                'main' => $this->collectionToArray($product, 'main'),
                'additional' => $this->collectionToArray($product, 'additional'),
            ],
        ], 201);
    }

    /**
     * Удалить изображение товара.
     *
     * Удаляет одно изображение (основное или дополнительное) по его ID.
     * ID изображения можно получить из ответа загрузки или из GET-списка.
     *
     * @param  Product  $product  ID товара
     * @param  int  $media  ID изображения (media)
     *
     * @response 200 { "success": true }
     */
    public function destroy(Product $product, int $media): JsonResponse
    {
        /** @var Media $item */
        $item = $product->media()
            ->whereIn('collection_name', self::COLLECTIONS)
            ->findOrFail($media);

        $item->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Изображения одной коллекции в виде массива.
     */
    private function collectionToArray(Product $product, string $collection): array
    {
        return $product->getMedia($collection)
            ->map(fn (Media $m) => $this->mediaToArray($m))
            ->values()
            ->toArray();
    }

    /**
     * Представление одного изображения для ответа API.
     */
    private function mediaToArray(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->getUrl(),
            'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
            'large_url' => $media->hasGeneratedConversion('large') ? $media->getUrl('large') : null,
            'file_name' => $media->file_name,
            'collection' => $media->collection_name,
            'order' => $media->order_column,
        ];
    }
}
