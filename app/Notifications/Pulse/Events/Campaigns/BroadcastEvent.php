<?php

namespace App\Notifications\Pulse\Events\Campaigns;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Письмо кампании — реклама по сегменту клиентов.
 *
 * Домен `campaigns` в гарде доставки требует согласия контакта и уважает
 * стоп-лист области «только рассылки». Граница между рекламой и уведомлением
 * о заказе проходит по домену, а не по усмотрению того, кто нажал отправку.
 */
class BroadcastEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'campaigns.broadcast';
    }

    public function label(): string
    {
        return 'Рассылка по сегменту';
    }

    public function description(): string
    {
        return 'Письмо кампании: акция, новость, персональное предложение';
    }

    public function fields(): array
    {
        return [
            'campaign_id' => new FieldSpec('campaign_id', 'Кампания', FieldSpec::TYPE_NUMBER),
            'campaign_name' => new FieldSpec('campaign_name', 'Название кампании', FieldSpec::TYPE_STRING),
        ];
    }

    protected function ownTags(array $data): array
    {
        return ['рассылка:кампания'];
    }

    public function defaultSubject(): string
    {
        return 'Новости Pecado.ru';
    }
}
