<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class AllImagesField extends ExportField
{
    public function key(): string { return 'all_images'; }
    public function name(): string { return 'Все изображения'; }
    public function description(): string { return 'URL всех изображений (главное + дополнительные)'; }
    public function group(): string { return 'Медиа'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'multi_value'; }
    public function eagerLoad(): array { return ['media']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        $main = $product->getMedia('main')->map(fn ($m) => $m->getFullUrl());
        $additional = $product->getMedia('additional')->map(fn ($m) => $m->getFullUrl());

        return $main->merge($additional)->implode(', ');
    }
}
