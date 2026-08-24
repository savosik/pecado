<?php

namespace App\Services\Crm\Mail;

use App\Models\CrmEmail;
use App\Models\PersonalManager;

/**
 * Что по каждому поводу всё ещё шлёт зашитый листенер.
 *
 * Пока идёт переезд на подписки (эпик bus-00), у части поводов два отправителя:
 * старый листенер за feature-флагом и новое правило-фильтр. Включить второй,
 * не выключив первый, — значит отправить клиенту два одинаковых письма.
 *
 * Карта нужна двум местам сразу: экрану поводов, чтобы менеджер видел, какой
 * флаг гасить перед подпиской, и автоотправке, чтобы дубль не ушёл, даже если
 * флаг гасить забыли. Второе важнее: порядок шагов можно перепутать, а письмо
 * клиенту отозвать нельзя.
 */
class LegacySenders
{
    /**
     * Повод → зашитые отправители. Их бывает несколько на один повод
     * с разной аудиторией: оформленный заказ уходит и клиенту, и менеджеру
     * двумя разными листенерами.
     *
     * `audience` — кому шлёт старый механизм: конфликт возникает только если
     * правило целится в того же адресата. То же письмо, но бухгалтеру из
     * справочника контактов, дублем не является.
     *
     * @var array<string, list<array{flag: string, audience: string, label: string}>>
     */
    private const MAP = [
        'orders.created' => [
            [
                'flag' => 'order_created',
                'audience' => 'client',
                'label' => 'MAIL_FEATURE_ORDER_CREATED — письмо клиенту об оформленном заказе',
            ],
            [
                'flag' => 'manager_new_order',
                'audience' => 'manager',
                'label' => 'MAIL_FEATURE_MANAGER_ORDER — письмо менеджеру о новом заказе',
            ],
        ],
        'orders.status_changed' => [
            [
                'flag' => 'order_status_changes',
                'audience' => 'client',
                'label' => 'MAIL_FEATURE_ORDER_STATUS — письмо клиенту о смене статуса',
            ],
        ],
        'system.return_created' => [
            [
                'flag' => 'return_created',
                'audience' => 'client',
                'label' => 'MAIL_FEATURE_RETURN_CREATED — письмо клиенту о заявке на возврат',
            ],
        ],
        'system.return_status_changed' => [
            [
                'flag' => 'return_status_changes',
                'audience' => 'client',
                'label' => 'MAIL_FEATURE_RETURN_STATUS — письмо клиенту о статусе возврата',
            ],
        ],
    ];

    /**
     * Какие зашитые отправители по этому поводу ещё включены.
     *
     * @return list<string>
     */
    public function activeFor(string $eventKey): array
    {
        $active = [];

        foreach (self::MAP[$eventKey] ?? [] as $sender) {
            if (config('notifications.mail.features.'.$sender['flag'])) {
                $active[] = $sender['label'];
            }
        }

        return $active;
    }

    /**
     * Уйдёт ли это письмо дублем к тому, кому уже пишет старый листенер.
     *
     * Проверяются фактические адреса письма, а не намерение правила: правило
     * могло целиться в роль контакта, а раскрыться в тот же адрес аккаунта.
     */
    public function conflictFor(CrmEmail $letter): ?string
    {
        $eventKey = (string) ($letter->origin_event ?? '');
        $addresses = array_map('mb_strtolower', array_map('strval', (array) $letter->to));

        if ($addresses === []) {
            return null;
        }

        foreach (self::MAP[$eventKey] ?? [] as $sender) {
            if (! config('notifications.mail.features.'.$sender['flag'])) {
                continue;
            }

            $target = $sender['audience'] === 'manager'
                ? $this->managerAddress($letter)
                : $letter->client?->email;

            if (filled($target) && in_array(mb_strtolower((string) $target), $addresses, true)) {
                return $sender['label'];
            }
        }

        return null;
    }

    private function managerAddress(CrmEmail $letter): ?string
    {
        $managerId = $letter->client?->personal_manager_id;

        if ($managerId === null) {
            return null;
        }

        return PersonalManager::query()->whereKey($managerId)->value('email');
    }
}
