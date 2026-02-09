<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

class AdditionalImagesField extends ExportField
{
    public function key(): string { return 'additional_images'; }
    public function name(): string { return 'Дополнительные изображения'; }
    public function description(): string { return 'URL дополнительных изображений через запятую'; }
    public function group(): string { return 'Медиа'; }
    public function isFilterable(): bool { return false; }
    public function modifierType(): ?string { return 'multi_value'; }
    public function eagerLoad(): array { return ['media']; }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return $product->getMedia('additional')
            ->map(fn ($media) => $media->getFullUrl())
            ->implode(', ');
    }
}
