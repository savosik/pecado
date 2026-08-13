<?php

namespace App\Models;

use App\Events\EntityChanged;
use App\Subscriptions\EntityChangeNotice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $order_id
 * @property string $type
 * @property string $summary
 * @property array<array-key, mixed> $changes
 * @property string $source
 * @property int|null $user_id
 * @property numeric|null $old_total
 * @property numeric|null $new_total
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Order|null $order
 * @property-read \App\Models\User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereChanges($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereNewTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereOldTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereSource($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereSummary($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderChangeLog whereUserId($value)
 *
 * @mixin \Eloquent
 */
class OrderChangeLog extends Model
{
    protected $fillable = [
        'order_id',
        'type',
        'summary',
        'changes',
        'source',
        'user_id',
        'old_total',
        'new_total',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'old_total' => 'decimal:2',
            'new_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        // Единая точка триггера уведомлений подписчикам раздела «Заказы»:
        // сюда попадают изменения из всех источников (ERP / админка / API),
        // т.к. все они создают OrderChangeLog через OrderChangeLogger.
        static::created(function (self $log): void {
            $order = $log->order;

            if (! $order || blank($order->user_id)) {
                return;
            }

            $number = $order->erp_number ?: $order->number;

            EntityChanged::dispatch(new EntityChangeNotice(
                section: 'orders',
                ownerUserId: (int) $order->user_id,
                title: sprintf('Изменение по заказу %s — Pecado.ru', $number),
                body: (string) $log->summary,
                url: url(route('cabinet.orders.show', $order, false)),
                entityLabel: "Заказ {$number}",
                rows: self::buildNoticeRows($log),
                event: (string) $log->type,
            ));
        });
    }

    /**
     * Структурированные блоки изменения для вёрстки письма подписки —
     * строятся из type + changes лога. Формат см. в EntityChangeNotice::$rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function buildNoticeRows(self $log): array
    {
        $rows = [];
        $c = $log->changes ?? [];

        switch ($log->type) {
            case 'attributes_updated':
                foreach (($c['attributes'] ?? []) as $field => $entry) {
                    $rows[] = [
                        'type' => 'diff',
                        'label' => $entry['label'] ?? $field,
                        'old' => self::fmtNoticeValue($entry['old_label'] ?? $entry['old'] ?? null),
                        'new' => self::fmtNoticeValue($entry['new_label'] ?? $entry['new'] ?? null),
                    ];
                }
                break;

            case 'items_updated':
                foreach (($c['added'] ?? []) as $i) {
                    $rows[] = ['type' => 'action', 'kind' => 'added', 'label' => 'Добавлен',
                        'text' => "«{$i['product_name']}» (кол-во: {$i['quantity']}, цена: ".self::money($i['price'] ?? 0).' ₽)'];
                }
                foreach (($c['removed'] ?? []) as $i) {
                    $rows[] = ['type' => 'action', 'kind' => 'removed', 'label' => 'Удалён',
                        'text' => "«{$i['product_name']}»"];
                }
                foreach (($c['modified'] ?? []) as $i) {
                    $parts = [];
                    $ch = $i['changes'] ?? [];
                    if (isset($ch['quantity'])) {
                        $parts[] = "кол-во: {$ch['quantity']['old']} → {$ch['quantity']['new']}";
                    }
                    if (isset($ch['discount_percent'])) {
                        $parts[] = "корректировка цены: {$ch['discount_percent']['old']}% → {$ch['discount_percent']['new']}%";
                    }
                    if (isset($ch['final_price'])) {
                        $parts[] = 'цена: '.self::money($ch['final_price']['old']).' → '.self::money($ch['final_price']['new']).' ₽';
                    }
                    $rows[] = ['type' => 'action', 'kind' => 'modified', 'label' => 'Изменён',
                        'text' => "«{$i['product_name']}» — ".implode(', ', $parts)];
                }
                if ($log->old_total !== null && $log->new_total !== null
                    && abs((float) $log->old_total - (float) $log->new_total) > 0.01) {
                    $rows[] = ['type' => 'diff', 'label' => 'Сумма заказа',
                        'old' => self::money($log->old_total).' ₽',
                        'new' => self::money($log->new_total).' ₽'];
                }
                break;

            case 'api_shortfall':
                $rows[] = ['type' => 'note', 'text' => 'Заказ по API принят не в полном объёме'];
                foreach (($c['not_accepted'] ?? []) as $i) {
                    $reason = $i['message'] ?? $i['reason'] ?? 'нет в наличии';
                    $rows[] = ['type' => 'action', 'kind' => 'shortfall', 'label' => 'Не принят',
                        'text' => "«{$i['product_name']}» — запрошено {$i['requested']} ({$reason})"];
                }
                foreach (($c['partial'] ?? []) as $i) {
                    $rows[] = ['type' => 'action', 'kind' => 'partial', 'label' => 'Частично',
                        'text' => "«{$i['product_name']}» — запрошено {$i['requested']}, принято {$i['fulfilled']}"];
                }
                break;
        }

        return $rows;
    }

    private static function fmtNoticeValue(mixed $value): string
    {
        return ($value === null || $value === '') ? '—' : (string) $value;
    }

    private static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', ' ');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
