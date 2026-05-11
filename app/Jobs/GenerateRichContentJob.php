<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Product\RichContent\RichContentGenerationException;
use App\Services\Product\RichContent\RichContentGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GenerateRichContentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $productId)
    {
        $this->onQueue('rich-content');
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function uniqueFor(): int
    {
        return (int) config('rich_content.lock_seconds', 60);
    }

    public function handle(RichContentGenerator $generator): void
    {
        if (! config('rich_content.enabled', true)) {
            return;
        }

        $product = Product::find($this->productId);
        if (! $product) {
            return;
        }

        if ($this->hasBlocks($product)) {
            return;
        }

        if ($this->isOnCooldown($product)) {
            return;
        }

        $maxAttempts = (int) config('rich_content.max_attempts', 3);
        if (($product->rich_content_generation_attempts ?? 0) >= $maxAttempts) {
            return;
        }

        $lock = Cache::lock(
            "rich-content-gen:{$product->id}",
            (int) config('rich_content.lock_seconds', 60),
        );

        if (! $lock->get()) {
            return;
        }

        try {
            $product->refresh();
            if ($this->hasBlocks($product)) {
                return;
            }

            $generator->generate($product);
        } catch (RichContentGenerationException $e) {
            $generator->recordFailure($product);
            Log::warning('RichContent: генерация не удалась', [
                'product_id' => $product->id,
                'product_slug' => $product->slug,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }
    }

    private function hasBlocks(Product $product): bool
    {
        $rc = $product->getAttribute('rich_content');
        if (! is_array($rc)) {
            return false;
        }

        $blocks = $rc['blocks'] ?? null;

        return is_array($blocks) && count($blocks) > 0;
    }

    private function isOnCooldown(Product $product): bool
    {
        $failedAt = $product->getAttribute('rich_content_generation_failed_at');
        if (! $failedAt instanceof \Illuminate\Support\Carbon) {
            return false;
        }

        $hours = (int) config('rich_content.failure_cooldown_hours', 24);

        return $failedAt->copy()->addHours($hours)->isFuture();
    }
}
