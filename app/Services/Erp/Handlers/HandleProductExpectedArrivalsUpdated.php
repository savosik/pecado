<?php

namespace App\Services\Erp\Handlers;

use App\Models\Product;
use App\Models\ProductExpectedArrival;
use App\Models\ProductExpectedArrivalSnapshot;
use App\Models\Warehouse;
use App\Services\Erp\ErpHandlerOutcome;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Обработка `product.expected_arrivals.updated` (1С → Сайт, v16.16.0, топик №16 Agent Hub).
 *
 * Сообщение — полный снимок ожидаемых поступлений одного товара по складам сайта.
 * Оно целиком заменяет всё, что сайт знал об ожиданиях товара: строки удаляются
 * и пишутся заново, склад вне снимка тем самым очищается, пустой `warehouses`
 * очищает товар полностью.
 *
 * ## Защита от перестановки
 *
 * Снимки одного товара могут прийти не в порядке сборки: суточная выгрузка идёт
 * параллельно с событием по изменению, брокер повторяет доставку. Поэтому по товару
 * хранится шапка с `calculated_at` последнего применённого снимка. Снимок, собранный
 * раньше, отбрасывается; равный применяется (повтор безвреден). Шапка блокируется
 * `lockForUpdate` внутри транзакции — сравнение и замена строк неразделимы, даже
 * если воркеров очереди станет больше одного. Шапка живёт и после очистки: иначе
 * опоздавший непустой снимок вернул бы ожидания, которых уже нет.
 *
 * ## Что не считается ошибкой
 *
 * - Склад, не заведённый на сайте, пропускается; остальные склады снимка применяются.
 * - Товар, которого на сайте нет, пропускается без исключения — сообщение не уходит
 *   ни в повтор, ни в DLQ. Его ожидания привезёт суточная контрольная выгрузка.
 * - Две строки с одинаковыми складом, датой и источником складываются.
 *
 * Дата в прошлом сохраняется как пришла: как её показывать, решает
 * {@see \App\Services\Stock\ExpectedArrivals}.
 */
class HandleProductExpectedArrivalsUpdated
{
    public function __construct(
        private readonly ErpHandlerOutcome $outcome,
    ) {}

    /** @param array<string, mixed> $payload */
    public function handle(array $payload): void
    {
        $productUuid = (string) $payload['product_uuid'];

        // Скрытые товары тоже принимаем: 1С — мастер каталога (HiddenScope обходим).
        $product = Product::withoutGlobalScopes()->where('external_id', $productUuid)->first();

        if ($product === null) {
            Log::info('product.expected_arrivals.updated: товар не найден по UUID, снимок пропущен', [
                'product_uuid' => $productUuid,
            ]);
            $this->outcome->addNote("Товар {$productUuid} на сайте не найден — снимок пропущен.");

            return;
        }

        $calculatedAt = Carbon::parse((string) $payload['calculated_at'])
            ->setTimezone(config('app.timezone'));

        [$rows, $unknownWarehouses] = $this->rows($product->id, $payload['warehouses'] ?? []);

        $staleAgainst = DB::transaction(function () use ($product, $calculatedAt, $rows, $payload) {
            $header = ProductExpectedArrivalSnapshot::query()
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if ($header !== null && $calculatedAt->lt($header->calculated_at)) {
                return $header->calculated_at;
            }

            ProductExpectedArrival::query()->where('product_id', $product->id)->delete();

            if ($rows !== []) {
                ProductExpectedArrival::query()->insert($rows);
            }

            $header ??= new ProductExpectedArrivalSnapshot(['product_id' => $product->id]);
            $header->fill([
                'calculated_at' => $calculatedAt,
                'received_at' => now(),
                'message_id' => $payload['message_id'] ?? null,
                'rows_count' => count($rows),
            ])->save();

            return null;
        });

        if ($staleAgainst !== null) {
            $reason = sprintf(
                'Снимок устарел: calculated_at %s раньше уже применённого по товару %s.',
                $calculatedAt->toIso8601String(),
                $staleAgainst->toIso8601String(),
            );

            Log::warning('product.expected_arrivals.updated: снимок отброшен как устаревший', [
                'product_uuid' => $productUuid,
                'calculated_at' => $calculatedAt->toIso8601String(),
                'applied_calculated_at' => $staleAgainst->toIso8601String(),
            ]);
            $this->outcome->markStale($reason);

            return;
        }

        if ($unknownWarehouses !== []) {
            // Не ошибка: 1С может знать склад, которого на сайте нет. В журнале шины
            // остаётся пометка при статусе success — для сверки, а не для разбора.
            $this->outcome->addNote('Склады не заведены на сайте и пропущены: '.implode(', ', $unknownWarehouses).'.');
        }

        Log::info('product.expected_arrivals.updated: снимок применён', [
            'product_id' => $product->id,
            'product_uuid' => $productUuid,
            'rows' => count($rows),
            'skipped_warehouses' => $unknownWarehouses,
        ]);
    }

    /**
     * Строки для вставки и список пропущенных складов.
     *
     * Ключ склейки — склад, дата, источник: 1С обещает одну строку на такую тройку,
     * но если придут две (в одном складе или склад перечислен дважды), количества
     * складываются, а не затирают друг друга.
     *
     * @param  array<int, array<string, mixed>>  $warehouses
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function rows(int $productId, array $warehouses): array
    {
        $uuids = array_values(array_unique(array_map(
            static fn (array $warehouse) => (string) $warehouse['warehouse_uuid'],
            $warehouses,
        )));

        $warehouseIds = $uuids === []
            ? []
            : Warehouse::query()->whereIn('external_id', $uuids)->pluck('id', 'external_id')->all();

        $now = now();
        $merged = [];
        $unknown = [];

        foreach ($warehouses as $warehouse) {
            $uuid = (string) $warehouse['warehouse_uuid'];
            $warehouseId = $warehouseIds[$uuid] ?? null;

            if ($warehouseId === null) {
                $unknown[$uuid] = $uuid;

                continue;
            }

            foreach ($warehouse['arrivals'] ?? [] as $arrival) {
                $date = $arrival['date'] ?? null;
                $source = (string) $arrival['source'];
                $key = $warehouseId.'|'.($date ?? '').'|'.$source;

                $merged[$key] ??= [
                    'product_id' => $productId,
                    'warehouse_id' => $warehouseId,
                    'expected_date' => $date,
                    'quantity' => 0.0,
                    'source' => $source,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $merged[$key]['quantity'] = round($merged[$key]['quantity'] + (float) $arrival['quantity'], 3);
            }
        }

        return [array_values($merged), array_values($unknown)];
    }
}
