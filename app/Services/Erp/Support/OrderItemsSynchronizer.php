<?php

namespace App\Services\Erp\Support;

use App\Enums\PromoKind;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Приведение позиций заказа к содержимому `items[]` из 1С (протокол v15.16.0).
 *
 * Общий для `order.created` и `order.updated`: 1С в обоих случаях присылает состав
 * документа целиком, и расхождение логики между обработчиками означало бы, что
 * заказ выглядит по-разному в зависимости от того, какое событие приехало последним.
 *
 * ## Почему не delete-and-recreate, как было раньше
 *
 * До v15.16.0 строку заказа нечем было идентифицировать: обработчики удаляли все
 * позиции и создавали заново. Отсюда три следствия, и все три — баги:
 *
 * 1. Складские привязки сайта (партия некондиции, промо-правило) обнулялись, и их
 *    приходилось переносить эвристикой по `product_id` — два трейта-костыля,
 *    один из которых написан уже после инцидента на проде.
 * 2. Две строки на один товар схлопывались в одну (в `HandleOrderUpdated` позиции
 *    складывались в массив с ключом `product_uuid`) — побеждала последняя.
 * 3. `id` позиций менялись при каждом обновлении, ломая ссылки на них.
 *
 * С `line_number` строка получает устойчивый ключ, и позиция **обновляется**.
 *
 * ## Сопоставление строк
 *
 * 1. По `line_number` — основной путь.
 * 2. FIFO по `product_id` среди несопоставленных — переходный путь для заказов,
 *    заведённых до v15.16.0, и для случая, когда 1С перенумеровала строки.
 *
 * Складские привязки, не разобранные сопоставлением, наследуются по товару из
 * снимка «до»: при дроблении строки на активную и отменённую обе обязаны
 * ссылаться на ту же партию некондиции.
 */
class OrderItemsSynchronizer
{
    /**
     * Привести позиции заказа к payload из 1С.
     *
     * @param  array<int, mixed>  $payloadItems  содержимое `items[]` — приходит из 1С
     *                                           и типизировано только схемой
     * @return float сумма заказа: отменённые строки в неё не входят
     */
    public function sync(Order $order, array $payloadItems): float
    {
        $rows = $this->parseRows($payloadItems);

        /** @var Collection<int, OrderItem> $existing */
        $existing = $order->items()->get();

        // Снимок складских привязок до изменений. Нерасходуемый: при дроблении
        // строки её партия некондиции должна достаться обеим половинам.
        $linksByProduct = $this->captureLinks($existing);

        [$byLine, $orphans] = $this->indexExisting($existing);

        $matchedIds = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $match = $this->matchExisting($row, $byLine, $orphans);

            if ($match !== null) {
                $matchedIds[] = $match->id;
            }

            $links = $this->resolveLinks($row, $match, $linksByProduct);
            $subtotal = round($row['quantity'] * $row['final_price'], 2);

            $fields = [
                'line_number' => $row['line_number'],
                'product_id' => $row['product_id'],
                'name' => $row['name'],
                'quantity' => $row['quantity'],
                'cancelled' => $row['cancelled'],
                'price' => $row['final_price'],
                'base_price' => $row['base_price'],
                'discount_percent' => $row['discount_percent'],
                'final_price' => $row['final_price'],
                'subtotal' => $subtotal,
                'product_defect_id' => $links['product_defect_id'],
                'defect_description' => $links['defect_description'],
                'promotion_rule_id' => $links['promotion_rule_id'],
                'promo_kind' => $links['promo_kind'],
            ];

            if ($match !== null) {
                $match->update($fields);
            } else {
                $order->items()->create($fields);
            }

            // Отменённая строка хранится и показывается клиенту, но заказ
            // на неё не выставляется: это несобранный недобор, а не товар.
            if (! $row['cancelled']) {
                $total += $subtotal;
            }
        }

        $this->deleteMissing($order, $existing, $matchedIds);

        return round($total, 2);
    }

    /**
     * Разбор payload-строк.
     *
     * Номер строки: из payload, иначе порядковый номер элемента. Второе —
     * деградация, а не режим: при перестановке строк в 1С привязки разъедутся.
     *
     * Позиция с неизвестным сайту товаром **сохраняется** со снимком имени.
     * До v15.16.0 `order.updated` такие строки молча выбрасывал, а `order.created`
     * сохранял — заказ менялся от того, какое событие приехало последним.
     *
     * @param  array<int, mixed>  $payloadItems
     * @return list<array<string, mixed>>
     */
    private function parseRows(array $payloadItems): array
    {
        $rows = [];

        foreach (array_values($payloadItems) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $productUuid = $item['product_uuid'] ?? null;
            $product = $productUuid
                ? Product::withoutGlobalScopes()->where('external_id', $productUuid)->first()
                : null;

            $quantity = (int) ($item['quantity'] ?? 0);
            $basePrice = (float) ($item['base_price'] ?? $item['price'] ?? 0);
            $finalPrice = (float) ($item['final_price'] ?? $item['price'] ?? $basePrice);

            $lineNumber = $item['line_number'] ?? null;

            $rows[] = [
                'line_number' => is_numeric($lineNumber) && (int) $lineNumber > 0
                    ? (int) $lineNumber
                    : $index + 1,
                'product_id' => $product?->id,
                'name' => $product
                    ? $product->name
                    : ($item['name'] ?? $productUuid ?? 'Неизвестный товар'),
                'quantity' => $quantity,
                'base_price' => $basePrice,
                'discount_percent' => (float) ($item['discount_percent'] ?? 0),
                'final_price' => $finalPrice,
                'cancelled' => (bool) ($item['cancelled'] ?? false),
                'is_promo' => (bool) ($item['is_promo'] ?? false),
                'promo_kind' => $item['promo_kind'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Разложить существующие позиции на индекс по номеру строки и пул остальных.
     *
     * Дубль номера в пределах заказа возможен (уникального ключа в БД нет
     * намеренно) — вторая такая позиция уходит в пул, а не затирает первую.
     *
     * @param  Collection<int, OrderItem>  $existing
     * @return array{0: array<int, OrderItem>, 1: array<int, list<OrderItem>>}
     */
    private function indexExisting(Collection $existing): array
    {
        $byLine = [];
        $orphans = [];

        foreach ($existing as $item) {
            $line = $item->line_number;

            if ($line !== null && ! isset($byLine[$line])) {
                $byLine[$line] = $item;

                continue;
            }

            $orphans[(int) $item->product_id][] = $item;
        }

        return [$byLine, $orphans];
    }

    /**
     * Найти существующую позицию под строку payload.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, OrderItem>  $byLine
     * @param  array<int, list<OrderItem>>  $orphans
     */
    private function matchExisting(array $row, array &$byLine, array &$orphans): ?OrderItem
    {
        $line = $row['line_number'];

        if (isset($byLine[$line])) {
            $match = $byLine[$line];
            unset($byLine[$line]);

            return $match;
        }

        $productId = (int) $row['product_id'];

        if ($productId > 0 && ! empty($orphans[$productId])) {
            return array_shift($orphans[$productId]);
        }

        return null;
    }

    /**
     * Снимок складских привязок по товару — нерасходуемый источник наследования.
     *
     * @param  Collection<int, OrderItem>  $existing
     * @return array<int, array<string, mixed>>
     */
    private function captureLinks(Collection $existing): array
    {
        $map = [];

        foreach ($existing as $item) {
            $productId = (int) $item->product_id;

            if ($productId === 0 || isset($map[$productId])) {
                continue;
            }

            if ($item->product_defect_id === null && $item->promo_kind === null) {
                continue;
            }

            $map[$productId] = [
                'product_defect_id' => $item->product_defect_id,
                'defect_description' => $item->defect_description,
                'promotion_rule_id' => $item->promotion_rule_id,
                'promo_kind' => $item->promo_kind,
            ];
        }

        return $map;
    }

    /**
     * Складские привязки для строки.
     *
     * `product_defect_id` / `promotion_rule_id` ведёт сайт — 1С про них не знает
     * и в payload не присылает, поэтому источник всегда наш: сопоставленная
     * позиция, иначе наследование по товару.
     *
     * Исключение — `promo_kind`: его 1С прислать может (менеджер добавил
     * промо-позицию вручную прямо в 1С), и присланное значение приоритетнее.
     *
     * @param  array<string, mixed>  $row
     * @param  array<int, array<string, mixed>>  $linksByProduct
     * @return array<string, mixed>
     */
    private function resolveLinks(array $row, ?OrderItem $match, array $linksByProduct): array
    {
        $source = $match !== null && ($match->product_defect_id !== null || $match->promo_kind !== null)
            ? [
                'product_defect_id' => $match->product_defect_id,
                'defect_description' => $match->defect_description,
                'promotion_rule_id' => $match->promotion_rule_id,
                'promo_kind' => $match->promo_kind,
            ]
            : ($linksByProduct[(int) $row['product_id']] ?? [
                'product_defect_id' => null,
                'defect_description' => null,
                'promotion_rule_id' => null,
                'promo_kind' => null,
            ]);

        if (! empty($row['promo_kind'])) {
            $source['promo_kind'] = (string) $row['promo_kind'];
        } elseif ($row['is_promo'] && $source['promo_kind'] === null) {
            // Флаг без вида: считаем позицию подотчётной — это безопаснее,
            // чем потерять признак промо совсем.
            $source['promo_kind'] = PromoKind::ACCOUNTABLE->value;
        }

        return $source;
    }

    /**
     * Удалить позиции, которых больше нет в документе 1С.
     *
     * @param  Collection<int, OrderItem>  $existing
     * @param  list<int>  $matchedIds
     */
    private function deleteMissing(Order $order, Collection $existing, array $matchedIds): void
    {
        $obsolete = $existing->reject(fn (OrderItem $item) => in_array($item->id, $matchedIds, true))
            ->pluck('id')
            ->all();

        if ($obsolete !== []) {
            $order->items()->whereIn('id', $obsolete)->delete();
        }
    }
}
