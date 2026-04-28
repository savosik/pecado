<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateRichContentJob;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /api/products/{product:slug}/rich-content
 *
 * Возвращает Editor.js JSON-блоки описания товара. Если поле rich_content
 * пустое — ставит фоновую задачу GenerateRichContentJob и возвращает 202.
 * Фронт перезапрашивает endpoint до 200 (готово) или 204 (генерация недоступна).
 *
 * Ответы:
 *   200 { blocks: [...] }   — готовый rich_content
 *   202                     — генерация запущена/идёт, повторите запрос позже
 *   204                     — генерация недоступна (cooldown, фича отключена, исчерпаны попытки)
 */
class ProductRichContentController extends Controller
{
    public function show(Product $product): JsonResponse|Response
    {
        $blocks = $this->extractBlocks($product);
        if ($blocks !== null) {
            return response()->json([
                'blocks' => $blocks,
                'cached' => true,
            ]);
        }

        if (! config('rich_content.enabled', true)) {
            return response()->noContent();
        }

        if ($this->isOnCooldown($product)) {
            return response()->noContent();
        }

        $maxAttempts = (int) config('rich_content.max_attempts', 3);
        if (($product->rich_content_generation_attempts ?? 0) >= $maxAttempts) {
            return response()->noContent();
        }

        // Job-уровень ShouldBeUnique гарантирует, что параллельные диспатчи
        // не создадут дублей в очереди.
        GenerateRichContentJob::dispatch($product->id);

        return response()->noContent(SymfonyResponse::HTTP_ACCEPTED);
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function extractBlocks(Product $product): ?array
    {
        /** @var mixed $rc */
        $rc = $product->getAttribute('rich_content');
        if (! is_array($rc)) {
            return null;
        }

        /** @var mixed $blocks */
        $blocks = $rc['blocks'] ?? null;
        if (! is_array($blocks) || count($blocks) === 0) {
            return null;
        }

        return array_values($blocks);
    }

    private function isOnCooldown(Product $product): bool
    {
        /** @var mixed $failedAt */
        $failedAt = $product->getAttribute('rich_content_generation_failed_at');
        if (! $failedAt instanceof Carbon) {
            return false;
        }

        $hours = (int) config('rich_content.failure_cooldown_hours', 24);

        return $failedAt->copy()->addHours($hours)->isFuture();
    }
}
