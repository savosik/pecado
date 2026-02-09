<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DownloadProductMediaJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 300;
    public int $backoff = 10;

    public function __construct(
        public int $productId,
        public array $itemData,
    ) {
        $this->onQueue('catalog-media');
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);

        if (!$product) {
            Log::warning("DownloadProductMediaJob: товар #{$this->productId} не найден");
            return;
        }

        $data = $this->itemData;

        // Clear existing media
        $product->clearMediaCollection('main');
        $product->clearMediaCollection('additional');
        $product->clearMediaCollection('video');

        // Main image
        $mainImage = $data['image_main'] ?? '';
        if (!empty($mainImage)) {
            try {
                $product->addMediaFromUrl($mainImage)->toMediaCollection('main');
            } catch (\Exception $e) {
                Log::warning("Ошибка загрузки main изображения для {$data['code']}: {$e->getMessage()}");
            }
        }

        // Additional images
        foreach ($data['additional_images'] ?? [] as $imgUrl) {
            if (!empty($imgUrl)) {
                try {
                    $product->addMediaFromUrl($imgUrl)->toMediaCollection('additional');
                } catch (\Exception $e) {
                    Log::warning("Ошибка загрузки доп. изображения для {$data['code']}: {$e->getMessage()}");
                }
            }
        }

        // Videos
        foreach ($data['product_videos'] ?? [] as $videoUrl) {
            if (!empty($videoUrl)) {
                try {
                    $product->addMediaFromUrl($videoUrl)->toMediaCollection('video');
                } catch (\Exception $e) {
                    Log::warning("Ошибка загрузки видео для {$data['code']}: {$e->getMessage()}");
                }
            }
        }

        $mediaCount = $product->media()->count();
        Log::info("Загружены медиа для {$data['code']}: {$mediaCount} файлов");
    }
}
