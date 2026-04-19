<?php

namespace App\Media;

use App\Models\Product;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\DefaultPathGenerator;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Кастомный генератор путей для медиафайлов.
 *
 * Для модели Product генерирует стабильный путь на основе артикула (code):
 *   products-media/{code}/{collection_name}/
 *
 * Это позволяет файлам оставаться в MinIO при удалении товара и
 * повторно подключаться к новому товару с тем же артикулом без скачивания.
 *
 * Для всех остальных моделей используется стандартный DefaultPathGenerator.
 */
class ProductPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        if ($this->isProduct($media)) {
            return $this->productPath($media).'/';
        }

        return (new DefaultPathGenerator)->getPath($media);
    }

    public function getPathForConversions(Media $media): string
    {
        if ($this->isProduct($media)) {
            return $this->productPath($media).'/conversions/';
        }

        return (new DefaultPathGenerator)->getPathForConversions($media);
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        if ($this->isProduct($media)) {
            return $this->productPath($media).'/responsive-images/';
        }

        return (new DefaultPathGenerator)->getPathForResponsiveImages($media);
    }

    private function isProduct(Media $media): bool
    {
        return $media->model_type === (new Product)->getMorphClass()
            && ! empty($media->getCustomProperty('product_code'));
    }

    private function productPath(Media $media): string
    {
        $code = $media->getCustomProperty('product_code');
        $collection = $media->collection_name;

        return "products-media/{$code}/{$collection}";
    }
}
