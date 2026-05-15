<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class MainImageField extends ExportField
{
    public function key(): string
    {
        return 'main_image';
    }

    public function name(): string
    {
        return 'Главное изображение';
    }

    public function description(): string
    {
        return 'URL главного изображения товара';
    }

    public function group(): string
    {
        return 'Медиа';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function eagerLoad(): array
    {
        return ['media'];
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $mediaUrls = $product->getExportRowCache('media_urls');
        if ($mediaUrls !== null) {
            return $mediaUrls['main'][0] ?? null;
        }

        return $product->getFirstMedia('main')?->getFullUrl();
    }
}
