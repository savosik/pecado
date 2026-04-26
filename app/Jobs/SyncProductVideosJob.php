<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncProductVideosJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 600;

    public int $backoff = 15;

    /**
     * @param  array<int, string>  $urls
     */
    public function __construct(
        public int $productId,
        public array $urls = [],
    ) {
        $this->onQueue('catalog-media');
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);
        if (! $product) {
            Log::warning("SyncProductVideosJob: товар #{$this->productId} не найден");

            return;
        }

        $product->clearMediaCollection('video');

        foreach ($this->urls as $url) {
            if (! $url) {
                continue;
            }
            try {
                $product->addMediaFromUrl($url)
                    ->withCustomProperties(['source_url' => $url, 'product_code' => $product->code])
                    ->toMediaCollection('video');
            } catch (\Throwable $e) {
                Log::warning("SyncProductVideosJob: {$product->code}: {$e->getMessage()}");
            }
        }
    }
}
