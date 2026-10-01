<?php

namespace App\Services\Erp\Support;

use App\Enums\Order\OrderLineCancelReason;
use App\Enums\PromoKind;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

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
 * Два прохода по всем строкам payload, а не поиск «на месте» для каждой:
 *
 * 1. По `line_number` **при том же товаре** — основной путь.
 * 2. FIFO по `product_id` среди несопоставленных — для заказов, заведённых до
 *    v15.16.0 (номеров строк нет), и для случая, когда 1С перенумеровала строки.
 *
 * Совпадение номера без совпадения товара сопоставлением не считается. Инцидент
 * ORD-2026-8666 (09.09.2026): 1С переставила строки заказа уценки, синхронизатор
 * сопоставил их по номеру и оставил на строках старые привязки к партиям — в
 * итоге три позиции ссылались на партии чужих артикулов, реализация закрыла не
 * те партии, а «К отгрузке» показывала складу не тот брак.
 *
 * Привязка следует за товаром, а не за номером строки, поэтому у одного товара с
 * несколькими партиями в заказе перестановка строк в 1С может поменять партии
 * между этими строками местами. На учёт это не влияет: резерв и списание партий
 * считаются по товару (см. DefectShipmentService).
 *
 * Складские привязки, не разобранные сопоставлением, наследуются по товару из
 * снимка «до»: при дроблении строки на активную и отменённую обе обязаны
 * ссылаться на ту же партию некондиции.
 *
 * ## Дробление строки при приёме заказа (v16.17.0)
 *
 * С v16.17.0 1С при приёме заказа сайта отменяет строку без свободного остатка, а при
 * частичном остатке дробит её: активная часть + отменённый хвост отдельной строкой со
 * своим номером. Хвоста в заказе сайта не было — он создаётся; активная часть
 * обновляется на месте. Если хвост занял номер соседней строки и сдвинул остальные,
 * первый проход по ним промахивается (номер тот же, товар другой), и их подбирает
 * второй — по товару. Во втором проходе при нескольких строках одного товара сначала
 * берётся позиция с тем же признаком отмены: иначе активная и отменённая половины
 * поменялись бы местами, и клиент получил бы второе письмо о той же нехватке.
 *
 * Причина отмены (`cancel_reason`) хранится только у отменённой строки; причина,
 * присланная у активной, отбрасывается. Неизвестное значение сводится к `other`.
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

        $matches = $this->matchRows($rows, $existing);

        $matchedIds = [];
        $total = 0.0;
        $cancelledNow = [];

        foreach ($rows as $index => $row) {
            $match = $matches[$index];

            if ($match !== null) {
                $matchedIds[] = $match->id;
            }

            // Строка стала отменённой этим сообщением: была активной и отменилась,
            // либо появилась сразу отменённой. Уже отменённая повторно не считается —
            // повторная доставка payload не должна сдвигать дату отмены в журнале.
            $becameCancelled = $row['cancelled'] && ($match === null || ! $match->cancelled);

            $links = $this->resolveLinks($row, $match, $linksByProduct);
            $subtotal = round($row['quantity'] * $row['final_price'], 2);

            $fields = [
                'line_number' => $row['line_number'],
                'product_id' => $row['product_id'],
                'name' => $row['name'],
                'quantity' => $row['quantity'],
                'cancelled' => $row['cancelled'],
                // Причина живёт только у отменённой строки. У уже отменённой она может
                // уточниться следующим сообщением (была «не передана» → out_of_stock):
                // обновляем значение, дату отмены при этом не трогаем.
                'erp_cancel_reason' => $row['cancelled'] ? $row['cancel_reason'] : null,
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

            // Журнал недоборов: дату отмены ставим в момент, когда сайт её увидел —
            // времени отмены строки в протоколе нет. Возврат строки в работу
            // (1С сняла признак) стирает и дату, и разметку менеджера: это уже
            // не недобор, и в журнале ему делать нечего.
            if ($becameCancelled) {
                $fields['cancelled_at'] = now();
                $cancelledNow[] = [
                    'name' => (string) $row['name'],
                    'quantity' => (float) $row['quantity'],
                    'reason' => $row['cancel_reason']?->value,
                ];
            } elseif ($match !== null && $match->cancelled && ! $row['cancelled']) {
                $fields['cancelled_at'] = null;
                $fields['cancel_reason_id'] = null;
                $fields['cancel_source_user_id'] = null;
                $fields['cancel_source_at'] = null;
                $fields['cancel_note'] = null;
                $fields['cancel_archived_at'] = null;
            }

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

        // pick-00: склад собирает вечером и в субботу без менеджера — о недоборе клиенту сообщает сайт.
        // Одно событие на сообщение 1С, а не на строку: письмо должно быть одно.
        if ($cancelledNow !== []) {
            event(new \App\Events\Order\OrderItemsCancelled($order, $cancelledNow));
        }

        return round($total, 2);
    }

    /**
     * Разбор payload-строк.
     *
     * Номер строки: из payload, иначе порядковый номер элемента. Второе —
     * деградация, а не режим: без настоящих номеров две строки одного товара
     * различить нечем, и при перестановке в 1С они могут поменяться местами.
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

            if ($productUuid && $product === null) {
                // Рассинхрон каталога, а не недобор: товар есть в 1С, но не найден
                // по UUID на сайте. Молча копить такие строки нельзя — алерт.
                Log::warning('ERP: позиция заказа с неизвестным сайту товаром', [
                    'product_uuid' => $productUuid,
                    'name' => $item['name'] ?? null,
                ]);
            }

            $quantity = (int) ($item['quantity'] ?? 0);
            $basePrice = (float) ($item['base_price'] ?? $item['price'] ?? 0);
            $finalPrice = (float) ($item['final_price'] ?? $item['price'] ?? $basePrice);

            $lineNumber = $item['line_number'] ?? null;
            $cancelled = (bool) ($item['cancelled'] ?? false);
            $rawReason = $item['cancel_reason'] ?? null;

            if ($cancelled && filled($rawReason) && ! OrderLineCancelReason::isKnown($rawReason)) {
                // Контракт: неизвестную причину принимаем как «другую», заказ из-за неё
                // не теряем. Предупреждение — чтобы новую причину 1С заметили и завели.
                Log::warning('ERP: неизвестная причина отмены строки заказа, трактуем как other', [
                    'cancel_reason' => is_scalar($rawReason) ? (string) $rawReason : gettype($rawReason),
                    'product_uuid' => $productUuid,
                    'line_number' => $lineNumber,
                ]);
            }

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
                'cancelled' => $cancelled,
                'cancel_reason' => $cancelled ? OrderLineCancelReason::fromErp($rawReason) : null,
                'is_promo' => (bool) ($item['is_promo'] ?? false),
                'promo_kind' => $item['promo_kind'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * Сопоставить строки payload с существующими позициями.
     *
     * Первый проход — номер строки при том же товаре; второй — FIFO по товару
     * среди всего, что осталось (позиции без номера, с чужим номером после
     * перенумерации в 1С, дубли номера), с предпочтением позиции с тем же
     * признаком отмены. Каждая позиция достаётся не более чем
     * одной строке. Дубль номера в пределах заказа возможен (уникального ключа
     * в БД нет намеренно) — по номеру берётся первая, вторая уходит во второй проход.
     *
     * Строки с неизвестным сайту товаром (`product_id` null) сопоставляются только
     * по номеру, и только с такой же безтоварной позицией.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, OrderItem>  $existing
     * @return array<int, OrderItem|null> по индексу строки payload
     */
    private function matchRows(array $rows, Collection $existing): array
    {
        /** @var array<int, OrderItem> $pool id → позиция, ещё не отданная ни одной строке */
        $pool = [];
        /** @var array<int, OrderItem> $byLine */
        $byLine = [];

        foreach ($existing as $item) {
            $pool[(int) $item->id] = $item;

            if ($item->line_number !== null && ! isset($byLine[$item->line_number])) {
                $byLine[$item->line_number] = $item;
            }
        }

        $matches = array_fill(0, count($rows), null);

        // Проход 1: тот же номер строки и тот же товар.
        foreach ($rows as $index => $row) {
            $candidate = $byLine[$row['line_number']] ?? null;

            if ($candidate === null || ! isset($pool[(int) $candidate->id])) {
                continue;
            }

            if ((int) $candidate->product_id !== (int) $row['product_id']) {
                continue;
            }

            $matches[$index] = $candidate;
            unset($pool[(int) $candidate->id]);
        }

        // Проход 2: FIFO по товару среди оставшихся, в порядке их id.
        /** @var array<int, list<OrderItem>> $orphans */
        $orphans = [];
        foreach ($pool as $item) {
            $orphans[(int) $item->product_id][] = $item;
        }

        // Сначала — позиции с тем же признаком отмены, затем всё, что осталось.
        // Двумя заходами, а не «лучшая для строки»: иначе отменённая строка payload
        // забрала бы единственную активную позицию раньше, чем до неё дошла активная.
        foreach ([true, false] as $sameStateOnly) {
            foreach ($rows as $index => $row) {
                if ($matches[$index] !== null) {
                    continue;
                }

                $productId = (int) $row['product_id'];

                if ($productId <= 0 || empty($orphans[$productId])) {
                    continue;
                }

                $matches[$index] = $this->takeOrphan($orphans[$productId], (bool) $row['cancelled'], $sameStateOnly);
            }
        }

        return $matches;
    }

    /**
     * Взять позицию товара из несопоставленных: с тем же признаком отмены, а при
     * `$sameStateOnly = false` — первую по порядку (FIFO).
     *
     * Без предпочтения по признаку перенумерованные половины раздробленной строки
     * могли сопоставиться крест-накрест: отменённая строка payload — с активной
     * позицией, и наоборот. Состав в итоге верный, но активная позиция «отменилась»
     * бы заново — со свежей датой в журнале недоборов и повторным письмом клиенту.
     *
     * @param  list<OrderItem>  $orphans  изменяется: взятая позиция удаляется
     */
    private function takeOrphan(array &$orphans, bool $cancelled, bool $sameStateOnly): ?OrderItem
    {
        foreach ($orphans as $position => $item) {
            if (! $sameStateOnly || (bool) $item->cancelled === $cancelled) {
                array_splice($orphans, $position, 1);

                return $item;
            }
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
