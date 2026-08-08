<?php

namespace App\Services\Order;

use App\Models\Order;
use App\Models\OrderChangeLog;

/**
 * Логирование изменений заказа (атрибуты + состав) для прозрачности
 * клиенту и администратору. Используется как обработчиками ERP, так и
 * контроллерами админ-панели.
 */
class OrderChangeLogger
{
    /**
     * Список атрибутов заказа, изменения которых отслеживаются.
     * Ключ — имя поля, значение — человекочитаемая метка.
     */
    private const TRACKED_ATTRIBUTES = [
        'company_id' => 'Компания',
        // v15.8.0: заказ может «переехать» между нашими юрлицами — 1С переоформила
        // документ. Менеджер должен видеть это в журнале, а не узнавать по счёту.
        'organization_id' => 'Организация',
        'delivery_address' => 'Адрес доставки',
        'comment' => 'Комментарий',
        'currency_code' => 'Валюта',
        'user_id' => 'Клиент',
        'exchange_rate' => 'Курс валюты',
        'rate_coefficient' => 'Коэффициент курса',
    ];

    /**
     * Снимок атрибутов заказа для сравнения до/после редактирования.
     * Метки (company_name, user_name) нужны для читаемого summary.
     */
    public function snapshotAttributes(Order $order): array
    {
        return [
            'company_id' => $order->company_id,
            'company_name' => $order->company?->name,
            'organization_id' => $order->organization_id,
            'organization_name' => $order->organization?->name,
            'delivery_address' => $order->delivery_address,
            'comment' => $order->comment,
            'total_amount' => (float) $order->total_amount,
            'currency_code' => $order->currency_code,
            'user_id' => $order->user_id,
            'user_name' => $order->user?->name,
            'exchange_rate' => $order->exchange_rate !== null ? (float) $order->exchange_rate : null,
            'rate_coefficient' => $order->rate_coefficient !== null ? (float) $order->rate_coefficient : null,
        ];
    }

    /**
     * Снимок позиций заказа.
     *
     * Ключ — external_id товара (UUID из 1С), либо фолбэк 'item_{id}' для позиций
     * без связанного товара.
     *
     * v15.16.0: если товар в заказе встречается не один раз, второй и последующие
     * ключи получают суффикс с номером строки. Просто ключевать по номеру нельзя:
     * у позиций, заведённых до появления колонки, его нет, и первое же обновление
     * такого заказа дало бы в журнале ложную пару «позиция удалена + добавлена».
     * С суффиксом дробление строки при недоборе читается так, как оно и произошло:
     * у исходной строки изменилось количество, рядом добавилась отменённая.
     *
     * Порядок фиксирован по номеру строки: без него суффиксы разъезжались бы
     * между двумя снимками одного заказа.
     */
    public function snapshotItems(Order $order): array
    {
        $snapshot = [];

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items */
        $items = $order->items()->with('product')
            ->orderByRaw('line_number is null')
            ->orderBy('line_number')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $key = $item->product?->external_id ?? 'item_'.$item->id;

            if (isset($snapshot[$key])) {
                $key .= '#'.($item->line_number ?? $item->id);
            }

            $snapshot[$key] = [
                'product_id' => $item->product_id,
                'slug' => $item->product?->slug,
                'name' => $item->product?->name ?? $item->name,
                'quantity' => (int) $item->quantity,
                'cancelled' => (bool) $item->cancelled,
                'base_price' => (float) $item->base_price,
                'discount_percent' => (float) $item->discount_percent,
                'final_price' => (float) $item->final_price,
            ];
        }

        return $snapshot;
    }

    /**
     * Записать лог атрибутных изменений, если diff непуст.
     */
    public function logAttributeChanges(
        Order $order,
        array $oldAttrs,
        array $newAttrs,
        string $source,
        ?int $userId = null,
    ): ?OrderChangeLog {
        $diff = $this->diffAttributes($oldAttrs, $newAttrs);

        if (empty($diff)) {
            return null;
        }

        return OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'attributes_updated',
            'summary' => $this->buildAttributesSummary($diff),
            'changes' => ['attributes' => $diff],
            'source' => $source,
            'user_id' => $userId,
            'old_total' => $oldAttrs['total_amount'] ?? null,
            'new_total' => $newAttrs['total_amount'] ?? null,
        ]);
    }

    /**
     * Записать лог изменений состава заказа, если diff непуст.
     */
    public function logItemChanges(
        Order $order,
        array $oldItems,
        array $newItems,
        float $oldTotal,
        float $newTotal,
        string $source,
        ?int $userId = null,
    ): ?OrderChangeLog {
        $diff = $this->diffItems($oldItems, $newItems);

        if (! $this->hasMeaningfulChanges($diff, $oldTotal, $newTotal)) {
            return null;
        }

        return OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'items_updated',
            'summary' => $this->buildItemsSummary($diff, $oldTotal, $newTotal),
            'changes' => $diff,
            'source' => $source,
            'user_id' => $userId,
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
        ]);
    }

    /**
     * Записать недостачу при приёме заказа по API — позиции, которые клиент
     * запросил, но которые не были приняты полностью из-за отсутствия остатков.
     * Отдельный тип `api_shortfall` (source `api`): не сворачивается с
     * последующими правками состава, показывается как «запрошено → принято».
     *
     * @param  array<int, array{product_id:?int, slug:?string, product_name:string, requested:int, reason?:string, message?:string}>  $notAccepted  запрошено, принято 0
     * @param  array<int, array{product_id:?int, slug:?string, product_name:string, requested:int, fulfilled:int}>  $partial  принято меньше запрошенного
     */
    public function logApiShortfall(Order $order, array $notAccepted, array $partial): ?OrderChangeLog
    {
        if (empty($notAccepted) && empty($partial)) {
            return null;
        }

        return OrderChangeLog::create([
            'order_id' => $order->id,
            'type' => 'api_shortfall',
            'summary' => $this->buildShortfallSummary($notAccepted, $partial),
            'changes' => [
                'not_accepted' => array_values($notAccepted),
                'partial' => array_values($partial),
            ],
            'source' => 'api',
            'user_id' => null,
        ]);
    }

    /**
     * Человекочитаемое описание недостачи при приёме по API.
     *
     * @param  array<int, array<string, mixed>>  $notAccepted
     * @param  array<int, array<string, mixed>>  $partial
     */
    public function buildShortfallSummary(array $notAccepted, array $partial): string
    {
        $lines = ['Заказ по API принят не в полном объёме:'];

        foreach ($notAccepted as $item) {
            $name = $item['product_name'] ?? '—';
            $reason = $item['message'] ?? 'Нет в наличии';
            $lines[] = "Не принят: «{$name}» (запрошено {$item['requested']}) — {$reason}";
        }

        foreach ($partial as $item) {
            $name = $item['product_name'] ?? '—';
            $lines[] = "Принят частично: «{$name}» — запрошено {$item['requested']}, принято {$item['fulfilled']}";
        }

        return implode("\n", $lines);
    }

    /**
     * Вычислить diff атрибутов заказа. Возвращает ассоциативный массив
     * field => ['old' => ..., 'new' => ..., 'label' => ..., опциональные
     * old_label/new_label для связей].
     */
    public function diffAttributes(array $old, array $new): array
    {
        $diff = [];

        foreach (self::TRACKED_ATTRIBUTES as $field => $label) {
            $oldValue = $old[$field] ?? null;
            $newValue = $new[$field] ?? null;

            if ($this->valuesEqual($oldValue, $newValue)) {
                continue;
            }

            $entry = [
                'label' => $label,
                'old' => $oldValue,
                'new' => $newValue,
            ];

            if ($field === 'company_id') {
                $entry['old_label'] = $old['company_name'] ?? null;
                $entry['new_label'] = $new['company_name'] ?? null;
            }
            if ($field === 'organization_id') {
                $entry['old_label'] = $old['organization_name'] ?? null;
                $entry['new_label'] = $new['organization_name'] ?? null;
            }
            if ($field === 'user_id') {
                $entry['old_label'] = $old['user_name'] ?? null;
                $entry['new_label'] = $new['user_name'] ?? null;
            }

            $diff[$field] = $entry;
        }

        return $diff;
    }

    /**
     * Вычислить diff позиций — массив с ключами added, removed, modified.
     */
    public function diffItems(array $old, array $new): array
    {
        $diff = ['added' => [], 'removed' => [], 'modified' => []];

        foreach ($new as $key => $item) {
            if (! isset($old[$key])) {
                $diff['added'][] = [
                    'product_id' => $item['product_id'] ?? null,
                    'slug' => $item['slug'] ?? null,
                    'product_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['final_price'],
                    // v15.16.0: строка могла появиться уже отменённой — так
                    // выглядит недобор, когда 1С дробит исходную строку надвое
                    'cancelled' => (bool) ($item['cancelled'] ?? false),
                ];
            }
        }

        foreach ($old as $key => $item) {
            if (! isset($new[$key])) {
                $diff['removed'][] = [
                    'product_id' => $item['product_id'] ?? null,
                    'slug' => $item['slug'] ?? null,
                    'product_name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['final_price'],
                ];
            }
        }

        foreach ($new as $key => $newItem) {
            if (! isset($old[$key])) {
                continue;
            }

            $oldItem = $old[$key];
            $changes = [];

            if ($oldItem['quantity'] !== $newItem['quantity']) {
                $changes['quantity'] = ['old' => $oldItem['quantity'], 'new' => $newItem['quantity']];
            }
            if (abs($oldItem['discount_percent'] - $newItem['discount_percent']) > 0.01) {
                $changes['discount_percent'] = ['old' => $oldItem['discount_percent'], 'new' => $newItem['discount_percent']];
            }
            if (abs($oldItem['final_price'] - $newItem['final_price']) > 0.01) {
                $changes['final_price'] = ['old' => $oldItem['final_price'], 'new' => $newItem['final_price']];
            }
            if (abs($oldItem['base_price'] - $newItem['base_price']) > 0.01) {
                $changes['base_price'] = ['old' => $oldItem['base_price'], 'new' => $newItem['base_price']];
            }
            // v15.16.0: отмена строки при недоборе — самое заметное для клиента
            // изменение состава, и без этой ветки оно попадало бы в журнал только
            // косвенно, через изменившуюся сумму заказа.
            if ((bool) ($oldItem['cancelled'] ?? false) !== (bool) ($newItem['cancelled'] ?? false)) {
                $changes['cancelled'] = [
                    'old' => (bool) ($oldItem['cancelled'] ?? false),
                    'new' => (bool) ($newItem['cancelled'] ?? false),
                ];
            }

            if (! empty($changes)) {
                $diff['modified'][] = [
                    'product_id' => $newItem['product_id'] ?? null,
                    'slug' => $newItem['slug'] ?? null,
                    'product_name' => $newItem['name'],
                    'changes' => $changes,
                ];
            }
        }

        return $diff;
    }

    /**
     * Построить человекочитаемое описание изменений атрибутов.
     */
    public function buildAttributesSummary(array $diff): string
    {
        $lines = [];

        foreach ($diff as $field => $entry) {
            $label = $entry['label'];

            if (in_array($field, ['company_id', 'organization_id'], true)) {
                $old = $entry['old_label'] ?? '—';
                $new = $entry['new_label'] ?? '—';
                $lines[] = "{$label}: «{$old}» → «{$new}»";

                continue;
            }

            if ($field === 'user_id') {
                $old = $entry['old_label'] ?? '—';
                $new = $entry['new_label'] ?? '—';
                $lines[] = "{$label}: «{$old}» → «{$new}»";

                continue;
            }

            $old = $this->formatValue($entry['old']);
            $new = $this->formatValue($entry['new']);
            $lines[] = "{$label}: {$old} → {$new}";
        }

        return implode("\n", $lines);
    }

    /**
     * Построить человекочитаемое описание изменений позиций.
     */
    public function buildItemsSummary(array $diff, float $oldTotal, float $newTotal): string
    {
        $lines = [];

        foreach ($diff['added'] as $item) {
            // Отменённая строка приходит из 1С уже отменённой — для клиента это
            // не «добавили товар», а «часть заказа не собрали»
            if (! empty($item['cancelled'])) {
                $lines[] = "Отменено при сборке: «{$item['product_name']}» — {$item['quantity']} шт., позиция отменена в 1С (нет в наличии)";

                continue;
            }

            $lines[] = "Добавлен: «{$item['product_name']}» (кол-во: {$item['quantity']}, цена: {$item['price']} ₽)";
        }

        foreach ($diff['removed'] as $item) {
            $lines[] = "Удалён: «{$item['product_name']}»";
        }

        foreach ($diff['modified'] as $item) {
            $parts = [];
            $ch = $item['changes'];

            if (isset($ch['quantity'])) {
                $parts[] = "кол-во: {$ch['quantity']['old']} → {$ch['quantity']['new']}";
            }
            if (isset($ch['discount_percent'])) {
                $parts[] = "корректировка цены: {$ch['discount_percent']['old']}% → {$ch['discount_percent']['new']}%";
            }
            if (isset($ch['final_price'])) {
                $parts[] = "цена: {$ch['final_price']['old']} → {$ch['final_price']['new']} ₽";
            }
            if (isset($ch['cancelled'])) {
                $parts[] = $ch['cancelled']['new']
                    ? 'позиция отменена в 1С (нет в наличии)'
                    : 'отмена позиции снята';
            }

            $changesStr = implode(', ', $parts);
            $lines[] = "Изменён: «{$item['product_name']}» — {$changesStr}";
        }

        if (abs($oldTotal - $newTotal) > 0.01) {
            $lines[] = 'Сумма заказа: '.number_format($oldTotal, 2, '.', ' ').' → '.number_format($newTotal, 2, '.', ' ').' ₽';
        }

        return implode("\n", $lines);
    }

    private function hasMeaningfulChanges(array $diff, float $oldTotal, float $newTotal): bool
    {
        return ! empty($diff['added'])
            || ! empty($diff['removed'])
            || ! empty($diff['modified'])
            || abs($oldTotal - $newTotal) > 0.01;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }
        if (is_float($a) || is_float($b)) {
            return abs((float) $a - (float) $b) < 0.0000001;
        }

        return (string) $a === (string) $b;
    }

    private function formatValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }

        return '«'.(string) $value.'»';
    }
}
