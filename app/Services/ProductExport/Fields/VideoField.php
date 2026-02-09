<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class VideoField extends ExportField
{
    public function key(): string { return 'video'; }
    public function name(): string { return 'Видео'; }
    public function description(): string { return 'URL видео товара'; }
    public function group(): string { return 'Медиа'; }
    public function isFilterable(): bool { return false; }
    public function eagerLoad(): array { return ['media']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $media = $product->getFirstMedia('video');
        return $media?->getFullUrl();
    }
}
