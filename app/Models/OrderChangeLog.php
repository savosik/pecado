<?php

namespace App\Models;

use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
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
        // Единая точка уведомлений об изменении заказа: сюда попадают правки
        // из всех источников (ERP / админка / API), т.к. все они создают
        // OrderChangeLog через OrderChangeLogger.
        static::created(function (self $log): void {
            $order = $log->order;

            if (! $order || blank($order->user_id)) {
                return;
            }

            self::composeLetter($log, $order, $order->erp_number ?: $order->number);
        });
    }

    /**
     * Сообщить матрице уведомлений об изменении.
     *
     * Кому уйдёт письмо — настройка партнёра (`orders.items_updated`,
     * `orders.attributes_updated`, `orders.shortfall`).
     */
    private static function composeLetter(self $log, Order $order, string $number): void
    {
        $eventKey = match ($log->type) {
            'items_updated' => 'orders.items_updated',
            'attributes_updated' => 'orders.attributes_updated',
            'api_shortfall' => 'orders.shortfall',
            default => null,
        };

        if ($eventKey === null) {
            return;
        }

        app(MailStream::class)->captureQuietly(new Occasion(
            key: $eventKey,
            clientUserId: (int) $order->user_id,
            companyId: $order->company_id,
            subject: $order,
            data: self::buildOccasionData($log, $order, $number),
            view: [
                'title' => sprintf('Изменение по заказу %s', $number),
                'body' => (string) $log->summary,
                'url' => url(route('cabinet.orders.show', $order, false)),
                'entity_label' => "Заказ {$number}",
                'rows' => self::buildNoticeRows($log),
            ],
            occurredAt: $log->created_at,
        ));
    }

    /**
     * Числа изменения для условий правил.
     *
     * Считаются из тех же `changes`, что и блоки письма, — второго источника
     * правды для одного и того же изменения быть не должно.
     *
     * @return array<string, mixed>
     */
    private static function buildOccasionData(self $log, Order $order, string $number): array
    {
        // getAttribute, а не $log->changes: `changes` — имя protected-свойства
        // самого Eloquent (список изменённых при сохранении атрибутов), и внутри
        // класса обращение к свойству побеждает магический доступ к колонке.
        // Из-за этого блоки письма и числа для условий приходили пустыми.
        $c = (array) $log->getAttribute('changes');

        $notAccepted = count($c['not_accepted'] ?? []);
        $partial = count($c['partial'] ?? []);
        $removed = count($c['removed'] ?? []);

        $data = [
            'order_number' => $number,
            'order_type' => $order->type?->value,
            'source' => $log->source,
            'total' => (float) ($order->total ?? 0),
        ];

        if ($log->type === 'items_updated') {
            $data += [
                'added_count' => count($c['added'] ?? []),
                'removed_count' => $removed,
                'modified_count' => count($c['modified'] ?? []),
                'old_total' => (float) ($log->old_total ?? 0),
                'new_total' => (float) ($log->new_total ?? 0),
                'total_delta' => (float) ($log->new_total ?? 0) - (float) ($log->old_total ?? 0),
                'has_removed' => $removed > 0,
            ];
        }

        if ($log->type === 'attributes_updated') {
            $data['changed_fields'] = array_keys($c['attributes'] ?? []);
        }

        if ($log->type === 'api_shortfall') {
            $data += [
                'shortfall_items_count' => $notAccepted + $partial,
                'is_full_cancel' => $notAccepted > 0 && $partial === 0 && count($c['accepted'] ?? []) === 0,
                'source' => 'api',
            ];
        }

        return $data;
    }

    /**
     * Структурированные блоки изменения для вёрстки письма — строятся из
     * type + changes лога и уезжают в `view.rows` уведомления.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function buildNoticeRows(self $log): array
    {
        $rows = [];
        // getAttribute, а не $log->changes: `changes` — имя protected-свойства
        // самого Eloquent (список изменённых при сохранении атрибутов), и внутри
        // класса обращение к свойству побеждает магический доступ к колонке.
        // Из-за этого блоки письма и числа для условий приходили пустыми.
        $c = (array) $log->getAttribute('changes');

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
                        'text' => '«'.($i['product_name'] ?? '—').'» (кол-во: '.($i['quantity'] ?? '—').', цена: '.self::money($i['price'] ?? 0).' ₽)'];
                }
                foreach (($c['removed'] ?? []) as $i) {
                    $rows[] = ['type' => 'action', 'kind' => 'removed', 'label' => 'Удалён',
                        'text' => '«'.($i['product_name'] ?? '—').'»'];
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
                        'text' => '«'.($i['product_name'] ?? '—').'» — '.implode(', ', $parts)];
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
                        'text' => '«'.($i['product_name'] ?? '—').'» — запрошено '.($i['requested'] ?? '—')." ({$reason})"];
                }
                foreach (($c['partial'] ?? []) as $i) {
                    $rows[] = ['type' => 'action', 'kind' => 'partial', 'label' => 'Частично',
                        'text' => '«'.($i['product_name'] ?? '—').'» — запрошено '.($i['requested'] ?? '—').', принято '.($i['fulfilled'] ?? '—')];
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
