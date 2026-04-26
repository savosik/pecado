<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncProductImagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public int $backoff = 10;

    /**
     * @param  array<int, string>  $additionalUrls
     */
    public function __construct(
        public int $productId,
        public ?string $mainUrl,
        public array $additionalUrls = [],
    ) {
        $this->onQueue('catalog-media');
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            Log::warning("SyncProductImagesJob: товар #{$this->productId} не найден");

            return;
        }

        $product->clearMediaCollection('main');
        $product->clearMediaCollection('additional');

        if ($this->mainUrl) {
            try {
                $product->addMediaFromUrl($this->mainUrl)
                    ->withCustomProperties(['source_url' => $this->mainUrl, 'product_code' => $product->code])
                    ->toMediaCollection('main');
            } catch (\Throwable $e) {
                Log::warning("SyncProductImagesJob: main для {$product->code}: {$e->getMessage()}");
            }
        }

        foreach ($this->additionalUrls as $url) {
            if (! $url) {
                continue;
            }
            try {
                $product->addMediaFromUrl($url)
                    ->withCustomProperties(['source_url' => $url, 'product_code' => $product->code])
                    ->toMediaCollection('additional');
            } catch (\Throwable $e) {
                Log::warning("SyncProductImagesJob: дополнительное для {$product->code}: {$e->getMessage()}");
            }
        }
    }
}
