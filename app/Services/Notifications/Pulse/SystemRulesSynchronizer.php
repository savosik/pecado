<?php

namespace App\Services\Notifications\Pulse;

use App\Enums\OrderType;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use Illuminate\Support\Facades\DB;

/**
 * Системные правила — текущая зашитая в код маршрутизация, ставшая видимой.
 *
 * До пульта поведение по умолчанию нельзя было ни увидеть, ни изменить без
 * релиза: персональный менеджер считался в OrderManagerRouting, список статусов
 * для клиента лежал константой в конфиге. Здесь то же поведение заводится
 * правилами, которые менеджер видит в пульте и может выключить.
 *
 * Синхронизация идемпотентна и **не перетирает** то, что правили руками:
 * обновляются название, описание и структура, а `is_active`, `priority`
 * и получатели остаются. Иначе очередной деплой молча вернул бы включённым
 * то, что РОП сознательно выключил.
 */
class SystemRulesSynchronizer
{
    /**
     * Приоритеты системных правил лежат выше пользовательских (100),
     * поэтому правило менеджера разбирается раньше и может перебить
     * поведение по умолчанию.
     */
    private const PRIORITY = 500;

    /**
     * @return array{created: int, updated: int}
     */
    public function sync(): array
    {
        $created = 0;
        $updated = 0;

        foreach ($this->definitions() as $definition) {
            $result = $this->syncOne($definition);
            $result === 'created' ? $created++ : $updated++;
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Описания системных правил.
     *
     * `active_when` читает текущее значение feature-флага: если письмо
     * выключено на проде, правило создаётся выключенным. Ни одно письмо
     * не должно «включиться само» от появления пульта.
     *
     * @return array<int, array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'system_key' => 'sys.orders.created.client',
                'name' => 'Оформлен заказ — клиенту',
                'description' => 'Письмо клиенту о принятой покупке. Заменяет прежний листенер SendOrdersPlacedEmail.',
                'event_key' => 'orders.created',
                'conditions' => [
                    'field' => 'order_type',
                    'op' => '!=',
                    'value' => OrderType::PROMO_SAMPLE->value,
                ],
                'active_when' => 'notifications.mail.features.order_created',
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_CLIENT_USER],
                ],
            ],
            [
                'system_key' => 'sys.orders.created.manager',
                'name' => 'Оформлен заказ — персональному менеджеру',
                'description' => 'Письмо менеджеру клиента с учётом замещения на время отсутствия. Резервный адрес используется, только если менеджера нет.',
                'event_key' => 'orders.created',
                'conditions' => null,
                'active_when' => 'notifications.mail.features.manager_new_order',
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_PERSONAL_MANAGER],
                    [
                        'kind' => NotificationRuleRecipient::KIND_CONFIG_LIST,
                        'value' => 'notifications.mail.order_fallback_recipients',
                        'is_fallback' => true,
                    ],
                ],
            ],
            [
                'system_key' => 'sys.orders.status_changed.client',
                'name' => 'Смена статуса заказа — клиенту',
                'description' => 'Только переходы, пришедшие из 1С, и только статусы, о которых сообщаем клиенту. Прежде список статусов лежал константой в конфиге — теперь это условие, которое можно менять здесь.',
                'event_key' => 'orders.status_changed',
                'conditions' => [
                    'all' => [
                        ['field' => 'from_erp', 'op' => '=', 'value' => true],
                        [
                            'field' => 'status',
                            'op' => 'in',
                            'value' => array_values((array) config('notifications.mail.order_statuses_to_notify_client', [])),
                        ],
                    ],
                ],
                'active_when' => 'notifications.mail.features.order_status_changes',
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_CLIENT_USER],
                ],
            ],
            [
                'system_key' => 'sys.orders.shortfall.manager',
                'name' => 'Недобор по заказу — персональному менеджеру',
                'description' => 'Менеджер узнаёт о недоборе, чтобы предложить замену.',
                'event_key' => 'orders.shortfall',
                'conditions' => null,
                'active_when' => null,
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_PERSONAL_MANAGER],
                ],
            ],
            [
                'system_key' => 'sys.returns.created.client',
                'name' => 'Оформлен возврат — клиенту',
                'description' => 'Подтверждение приёма заявки на возврат. Заменяет прежний листенер SendReturnCreatedEmail.',
                'event_key' => 'system.return_created',
                'conditions' => null,
                'active_when' => 'notifications.mail.features.return_created',
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_CLIENT_USER],
                ],
            ],
            [
                'system_key' => 'sys.returns.created.manager',
                'name' => 'Оформлен возврат — персональному менеджеру',
                'description' => 'Менеджер узнаёт о возврате своего клиента, чтобы разобраться с причиной.',
                'event_key' => 'system.return_created',
                'conditions' => null,
                'active_when' => null,
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_PERSONAL_MANAGER],
                ],
            ],
            [
                'system_key' => 'sys.returns.status_changed.client',
                'name' => 'Смена статуса возврата — клиенту',
                'description' => 'Заменяет прежний листенер SendReturnStatusChangedEmail.',
                'event_key' => 'system.return_status_changed',
                'conditions' => null,
                'active_when' => 'notifications.mail.features.return_status_changes',
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_CLIENT_USER],
                ],
            ],
            [
                'system_key' => 'sys.questions.received.staff',
                'name' => 'Вопрос с сайта — на общий адрес',
                'description' => 'У вопроса нет владельца в данных, поэтому адресат задан списком из настроек — как это было до пульта.',
                'event_key' => 'system.question_received',
                'conditions' => null,
                'active_when' => null,
                'recipients' => [
                    [
                        'kind' => NotificationRuleRecipient::KIND_CONFIG_LIST,
                        'value' => 'notifications.mail.user_question_recipients',
                    ],
                ],
            ],
            [
                'system_key' => 'sys.orders.items_updated.client',
                'name' => 'Изменился состав заказа — клиенту',
                'description' => 'Ограничение частоты: 1С правит заказ построчно, и без него клиент получил бы десяток писем об одном изменении.',
                'event_key' => 'orders.items_updated',
                'conditions' => null,
                'active_when' => null,
                'throttle_seconds' => 300,
                'recipients' => [
                    ['kind' => NotificationRuleRecipient::KIND_CLIENT_USER],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function syncOne(array $definition): string
    {
        return DB::transaction(function () use ($definition): string {
            $existing = NotificationRule::withTrashed()
                ->where('system_key', $definition['system_key'])
                ->first();

            $structural = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'event_key' => $definition['event_key'],
                'conditions' => $definition['conditions'],
                'is_system' => true,
                'scope_type' => NotificationRule::SCOPE_GLOBAL,
            ];

            if ($existing !== null) {
                // Ручные правки сохраняем: обновляем только описание правила,
                // не трогая включённость, приоритет и получателей.
                $existing->fill($structural)->save();

                if ($existing->trashed()) {
                    $existing->restore();
                }

                return 'updated';
            }

            $rule = NotificationRule::create($structural + [
                'system_key' => $definition['system_key'],
                'priority' => self::PRIORITY,
                'is_active' => $this->initialActiveState($definition['active_when'] ?? null),
                'throttle_seconds' => $definition['throttle_seconds'] ?? null,
                'channel' => 'email',
                'digest' => 'none',
            ]);

            foreach ($definition['recipients'] as $recipient) {
                $rule->recipients()->create($recipient + ['copy_type' => 'to']);
            }

            return 'created';
        });
    }

    /**
     * Включать ли правило при первом создании.
     *
     * Берём текущее значение feature-флага: выключенное письмо остаётся
     * выключенным, включение — осознанное действие человека.
     */
    private function initialActiveState(?string $configKey): bool
    {
        if ($configKey === null) {
            return false;
        }

        return (bool) config($configKey, false);
    }
}
