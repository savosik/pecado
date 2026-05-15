<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class AllImagesField extends ExportField
{
    public function key(): string
    {
        return 'all_images';
    }

    public function name(): string
    {
        return 'Все изображения';
    }

    public function description(): string
    {
        return 'URL всех изображений (главное + дополнительные)';
    }

    public function group(): string
    {
        return 'Медиа';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function modifierType(): ?string
    {
        return 'multi_value';
    }

    public function eagerLoad(): array
    {
        return ['media'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return implode(', ', $this->resolveAllUrls($product));
    }

    public function nativeValue(Product $product, ?User $clientUser = null): mixed
    {
        return $this->resolveAllUrls($product);
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAllUrls(Product $product): array
    {
        $mediaUrls = $product->getExportRowCache('media_urls');
        if ($mediaUrls !== null) {
            return $mediaUrls['all'] ?? [];
        }

        $main = $product->getMedia('main')->map(fn ($m) => $m->getFullUrl())->all();
        $additional = $product->getMedia('additional')->map(fn ($m) => $m->getFullUrl())->all();

        return array_merge($main, $additional);
    }
}
