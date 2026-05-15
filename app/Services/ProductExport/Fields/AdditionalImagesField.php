<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class AdditionalImagesField extends ExportField
{
    public function key(): string
    {
        return 'additional_images';
    }

    public function name(): string
    {
        return 'Дополнительные изображения';
    }

    public function description(): string
    {
        return 'URL дополнительных изображений через запятую';
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
        $urls = $this->resolveAdditionalUrls($product);

        return implode(', ', $urls);
    }

    public function nativeValue(Product $product, ?User $clientUser = null): mixed
    {
        return $this->resolveAdditionalUrls($product);
    }

    /**
     * @return array<int, string>
     */
    protected function resolveAdditionalUrls(Product $product): array
    {
        $mediaUrls = $product->getExportRowCache('media_urls');
        if ($mediaUrls !== null) {
            return $mediaUrls['additional'] ?? [];
        }

        return $product->getMedia('additional')
            ->map(fn ($media) => $media->getFullUrl())
            ->values()
            ->all();
    }
}
