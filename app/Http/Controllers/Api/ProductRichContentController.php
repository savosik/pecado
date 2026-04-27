<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Product\RichContent\RichContentGenerationException;
use App\Services\Product\RichContent\RichContentGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /api/products/{product:slug}/rich-content
 *
 * Возвращает Editor.js JSON-блоки описания товара. Если поле rich_content
 * пустое — генерирует через OpenRouter и сохраняет результат.
 *
 * Ответы:
 *   200 { blocks: [...] }   — готовый или только что сгенерированный контент
 *   204                     — генерация недоступна (cooldown, короткое описание, фича отключена)
 *   500                     — ошибка генерации (фронт оставит fallback на description_html)
 */
class ProductRichContentController extends Controller
{
    public function __construct(
        private readonly RichContentGenerator $generator,
    ) {}

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

        $lock = Cache::lock(
            "rich-content-gen:{$product->id}",
            (int) config('rich_content.lock_seconds', 60),
        );

        if (! $lock->get()) {
            // Параллельный процесс уже генерирует — фронт перезапросит позже.
            return response()->noContent(SymfonyResponse::HTTP_ACCEPTED);
        }

        try {
            $product->refresh();
            $blocks = $this->extractBlocks($product);
            if ($blocks !== null) {
                return response()->json([
                    'blocks' => $blocks,
                    'cached' => true,
                ]);
            }

            $payload = $this->generator->generate($product);

            return response()->json([
                'blocks' => $payload['blocks'],
                'cached' => false,
            ]);
        } catch (InsufficientSourceTextException) {
            // Описание слишком короткое — повторять бессмысленно, cooldown не нужен.
            return response()->noContent();
        } catch (RichContentGenerationException $e) {
            $this->generator->recordFailure($product);
            Log::warning('RichContent: генерация не удалась', [
                'product_id' => $product->id,
                'product_slug' => $product->slug,
                'error' => $e->getMessage(),
            ]);

            $body = ['message' => 'Не удалось сгенерировать описание.'];
            if (config('app.debug')) {
                $body['reason'] = $e->getMessage();
            }

            return response()->json($body, SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR);
        } finally {
            $lock->release();
        }
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
