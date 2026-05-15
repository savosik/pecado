<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

/**
 * Поле «Изображение по позиции» (виртуальное).
 *
 * Ключ — `image.{N}`, где N — 0-индексированная позиция в списке всех изображений
 * товара (main + additional, в порядке загрузки). Резолвится FieldRegistry'ем
 * для любого N без явной регистрации.
 *
 * Why: партнёры ожидают отдельные колонки на каждое изображение (image, image1,
 * image2…), а не одну колонку со списком URL через запятую.
 */
class ImageByPositionField extends ExportField
{
    public function __construct(protected int $position) {}

    public function key(): string
    {
        return "image.{$this->position}";
    }

    public function name(): string
    {
        return $this->position === 0
            ? 'Изображение (главное)'
            : "Изображение #{$this->position}";
    }

    public function description(): string
    {
        return 'URL изображения по порядку (0 — главное, 1+ — дополнительные)';
    }

    public function group(): string
    {
        return 'Медиа';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function isExportable(): bool
    {
        return true;
    }

    public function eagerLoad(): array
    {
        return ['media'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $mediaUrls = $product->getExportRowCache('media_urls');
        if ($mediaUrls !== null) {
            return $mediaUrls['all'][$this->position] ?? null;
        }

        $main = $product->getMedia('main')->map(fn ($m) => $m->getFullUrl())->all();
        $additional = $product->getMedia('additional')->map(fn ($m) => $m->getFullUrl())->all();
        $all = array_merge($main, $additional);

        return $all[$this->position] ?? null;
    }
}
