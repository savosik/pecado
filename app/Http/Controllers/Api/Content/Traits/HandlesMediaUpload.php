<?php

namespace App\Http\Controllers\Api\Content\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Trait для загрузки медиафайлов через file upload или URL.
 *
 * Поддерживает два способа:
 * 1. multipart/form-data — поле с файлом (например, `list_item`)
 * 2. URL — поле с суффиксом `_url` (например, `list_item_url`)
 */
trait HandlesMediaUpload
{
    /**
     * Загрузить медиа в коллекцию из файла или URL.
     *
     * @param  Model  $model  Eloquent-модель с HasMedia
     * @param  string  $field  Имя поля в запросе (например, 'list_item')
     * @param  string  $collection  Имя медиа-коллекции (например, 'list-item')
     * @param  bool  $clearFirst  Очистить коллекцию перед загрузкой
     */
    protected function handleMediaUpload(
        Request $request,
        Model $model,
        string $field,
        string $collection,
        bool $clearFirst = false,
    ): void {
        $urlField = "{$field}_url";

        $hasFile = $request->hasFile($field);
        $hasUrl = $request->filled($urlField);

        if (! $hasFile && ! $hasUrl) {
            return;
        }

        if ($clearFirst) {
            $model->clearMediaCollection($collection);
        }

        if ($hasFile) {
            $model->addMediaFromRequest($field)->toMediaCollection($collection);
        } elseif ($hasUrl) {
            $model->addMediaFromUrl($request->input($urlField))->toMediaCollection($collection);
        }
    }

    /**
     * Загрузить массив медиа (например, галерея).
     */
    protected function handleMediaArrayUpload(
        Request $request,
        Model $model,
        string $field,
        string $collection,
    ): void {
        // Файлы
        if ($request->hasFile($field)) {
            foreach ($request->file($field) as $file) {
                $model->addMedia($file)->toMediaCollection($collection);
            }
        }

        // URL-массив
        $urlField = "{$field}_urls";
        if ($request->filled($urlField)) {
            foreach ($request->input($urlField) as $url) {
                $model->addMediaFromUrl($url)->toMediaCollection($collection);
            }
        }
    }

    /**
     * Получить URL медиа-коллекций модели.
     */
    protected function getMediaUrls(Model $model, array $collections): array
    {
        $urls = [];
        foreach ($collections as $collection) {
            $key = str_replace('-', '_', $collection);
            $urls[$key] = $model->getFirstMediaUrl($collection) ?: null;
        }

        return $urls;
    }
}
