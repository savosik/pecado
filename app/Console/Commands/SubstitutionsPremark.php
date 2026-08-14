<?php

namespace App\Console\Commands;

use App\Enums\Substitution\LinkKind;
use App\Enums\Substitution\LinkSource;
use App\Models\Product;
use App\Models\ProductSubstitution;
use App\Services\Substitution\SubstitutionCandidateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ИИ-предразметка справочника замен — строго дефицитное ядро, не каталог.
 *
 * Ядро = товары с ≥ N недоборами за окно (сегодня это десятки позиций, не
 * тысячи) плюс свежие товары, приземлившиеся в категории ядра — так «новый
 * товар в дефицитной категории попадает в очередь сам», без надежды на
 * память людей.
 *
 * Все созданные связи — source = ai, confirmed_at = NULL: автоподбор их не
 * использует, пока менеджер не подтвердит в очереди /crm/shortages/links.
 * Без ключа OpenRouter работает эвристика (головное слово + близость цены).
 */
class SubstitutionsPremark extends Command
{
    protected $signature = 'substitutions:premark
        {--days=90 : Окно недоборов, дней}
        {--min-shortages=2 : Порог отмен, с которого товар попадает в ядро}
        {--per-product=3 : Сколько связей заводить на товар ядра}
        {--fresh-days=30 : Товары новее скольких дней считать новинками ядра}
        {--dry-run : Показать план, ничего не создавая}';

    protected $description = 'Предразметить связи замен по дефицитному ядру (ai, требуют подтверждения)';

    public function __construct(
        private readonly SubstitutionCandidateService $engine,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('substitutions.enabled') && ! $this->option('dry-run')) {
            $this->warn('Контур замен выключен (SHORTAGE_OFFERS_ENABLED=false) — предразметка не выполняется.');

            return self::SUCCESS;
        }

        $core = $this->deficitCore();

        if ($core->isEmpty()) {
            $this->info('Дефицитное ядро пусто — недоборов за окно нет.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Дефицитное ядро: %d товаров (≥%d отмен за %d дней).', $core->count(), (int) $this->option('min-shortages'), (int) $this->option('days')));

        $dryRun = (bool) $this->option('dry-run');
        $perProduct = (int) $this->option('per-product');
        $created = 0;
        $skipped = 0;
        $rows = [];

        foreach ($core as $coreRow) {
            $product = Product::withoutGlobalScopes()->with('category')->find($coreRow->product_id);

            if ($product === null) {
                continue;
            }

            $existing = ProductSubstitution::query()
                ->where('from_product_id', $product->id)
                ->count();

            if ($existing >= $perProduct) {
                $skipped++;

                continue;
            }

            $pool = $this->candidatePool($product);

            if ($pool->isEmpty()) {
                $rows[] = [$product->name, (int) $coreRow->shortages, 'пул пуст — нужен ручной подбор'];

                continue;
            }

            $suggestions = $this->suggest($product, $pool, $perProduct - $existing);

            foreach ($suggestions as $suggestion) {
                if ($dryRun) {
                    $created++;
                    $rows[] = [$product->name, (int) $coreRow->shortages, '→ '.$suggestion['name']];

                    continue;
                }

                $link = ProductSubstitution::query()->firstOrCreate(
                    [
                        'from_product_id' => $product->id,
                        'to_product_id' => $suggestion['product_id'],
                    ],
                    [
                        'kind' => $suggestion['kind'],
                        'source' => LinkSource::AI,
                        'score' => $suggestion['score'],
                        'note' => $suggestion['note'],
                        'confirmed_at' => null,
                    ],
                );

                if ($link->wasRecentlyCreated) {
                    $created++;
                    $rows[] = [$product->name, (int) $coreRow->shortages, '→ '.$suggestion['name']];
                }
            }
        }

        if ($rows !== []) {
            $this->table(['Товар ядра', 'Отмен', 'Предложение'], $rows);
        }

        $this->info(sprintf(
            'Создано ai-связей: %d%s. Подтверждение — /crm/shortages/links.',
            $created,
            $dryRun ? ' (dry-run, ничего не записано)' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * Товары с повторными недоборами за окно (по бизнес-дате заказа).
     *
     * @return \Illuminate\Support\Collection<int, object{product_id: int, shortages: int}>
     */
    private function deficitCore(): \Illuminate\Support\Collection
    {
        $since = now()->subDays((int) $this->option('days'))->toDateTimeString();

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('order_items.cancelled', true)
            ->whereNotNull('order_items.product_id')
            ->whereNull('orders.deleted_at')
            ->whereRaw('COALESCE(orders.erp_created_at, orders.created_at) >= ?', [$since])
            ->groupBy('order_items.product_id')
            ->havingRaw('COUNT(*) >= ?', [(int) $this->option('min-shortages')])
            ->selectRaw('order_items.product_id, COUNT(*) as shortages')
            ->orderByDesc('shortages')
            ->get();
    }

    /**
     * Пул кандидатов: та же категория, коридор от базовой цены; новинки
     * (моложе fresh-days) включаются всегда — они и есть ответ на
     * «связывать, когда появился новый товар».
     *
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function candidatePool(Product $product): \Illuminate\Support\Collection
    {
        if ($product->category_id === null) {
            return collect();
        }

        $down = (float) config('substitutions.matching.price_corridor_down', 0.25);
        $up = (float) config('substitutions.matching.price_corridor_up', 0.10);
        $base = (float) $product->base_price;

        $existingPairs = ProductSubstitution::query()
            ->where('from_product_id', $product->id)
            ->pluck('to_product_id')
            ->all();

        $freshSince = now()->subDays((int) $this->option('fresh-days'));

        return Product::query()
            ->where('category_id', $product->category_id)
            ->where('id', '!=', $product->id)
            ->whereNotIn('id', $existingPairs)
            ->where(function ($query) use ($base, $down, $up, $freshSince) {
                $query->whereBetween('base_price', [$base * (1 - $down), $base * (1 + $up)])
                    ->orWhere('created_at', '>=', $freshSince);
            })
            ->limit(40)
            ->get();
    }

    /**
     * Отбор пар: LLM через OpenRouter, при недоступности — эвристика
     * (то же головное слово + близость цены).
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $pool
     * @return list<array{product_id: int, name: string, kind: LinkKind, score: int, note: string}>
     */
    private function suggest(Product $product, \Illuminate\Support\Collection $pool, int $limit): array
    {
        $llm = $this->suggestViaLlm($product, $pool, $limit);

        if ($llm !== null) {
            return $llm;
        }

        // Эвристика: приоритет — то же головное слово, затем близость цены.
        $head = $this->engine->normalizedHeadWord($product->name);
        $base = (float) $product->base_price;

        return $pool
            ->sortBy(function (Product $candidate) use ($head, $base) {
                $sameHead = $this->engine->normalizedHeadWord($candidate->name) === $head ? 0 : 1;
                $priceDistance = $base > 0 ? abs((float) $candidate->base_price - $base) / $base : 1;

                return [$sameHead, $priceDistance];
            })
            ->take($limit)
            ->map(fn (Product $candidate) => [
                'product_id' => $candidate->id,
                'name' => $candidate->name,
                'kind' => LinkKind::EQUIVALENT,
                'score' => 45,
                'note' => 'Аналог по назначению и цене (эвристика, проверьте текст)',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Product>  $pool
     * @return list<array{product_id: int, name: string, kind: LinkKind, score: int, note: string}>|null
     */
    private function suggestViaLlm(Product $product, \Illuminate\Support\Collection $pool, int $limit): ?array
    {
        $apiKey = (string) config('search.embedder.api_key');

        if ($apiKey === '') {
            return null;
        }

        $candidatesList = $pool->map(fn (Product $candidate) => sprintf(
            '- id=%d | %s | %.0f ₽',
            $candidate->id,
            $candidate->name,
            (float) $candidate->base_price,
        ))->implode("\n");

        $prompt = <<<PROMPT
Ты — товаровед оптового магазина интимных товаров. Товар часто заканчивается на складе,
и клиентам-оптовикам нужно предлагать замену. Клиент уже продал товар своему покупателю,
поэтому замена обязана выполнять ту же функцию (вибратор нельзя заменить смазкой)
и быть в близкой цене.

Товар, которому ищем замены:
{$product->name} — {$product->base_price} ₽

Кандидаты из той же категории:
{$candidatesList}

Выбери до {$limit} лучших замен. Верни строго JSON-массив объектов:
[{"id": число, "kind": "equivalent|analog_volume|downgrade|upgrade", "score": 0-100,
"note": "короткое объяснение замены для клиента по-русски, без канцелярита"}]
Если достойных замен нет — верни [].
PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://openrouter.ai/api/v1/chat/completions', [
                    'model' => env('OPENROUTER_MODEL', 'google/gemini-2.0-flash-001'),
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'temperature' => 0.2,
                ]);

            if (! $response->successful()) {
                Log::warning('Предразметка замен: OpenRouter недоступен', ['status' => $response->status()]);

                return null;
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');

            if (! preg_match('/\[.*\]/s', $content, $matches)) {
                return null;
            }

            $parsed = json_decode($matches[0], true);

            if (! is_array($parsed)) {
                return null;
            }

            $byId = $pool->keyBy('id');
            $result = [];

            foreach (array_slice($parsed, 0, $limit) as $row) {
                $candidate = $byId->get((int) ($row['id'] ?? 0));

                if ($candidate === null || blank($row['note'] ?? null)) {
                    continue;
                }

                $result[] = [
                    'product_id' => $candidate->id,
                    'name' => $candidate->name,
                    'kind' => LinkKind::tryFrom((string) ($row['kind'] ?? '')) ?? LinkKind::EQUIVALENT,
                    'score' => max(0, min(100, (int) ($row['score'] ?? 50))),
                    'note' => mb_strimwidth(trim((string) $row['note']), 0, 255, '…'),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Предразметка замен: ошибка вызова LLM', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
