<?php

namespace App\Listeners;

use App\Models\ClientContact;
use App\Models\NotificationDelivery;
use App\Models\NotificationSuppression;
use Illuminate\Support\Facades\Log;

/**
 * Отметка неудачной отправки письма пульта.
 *
 * Мотив прикладной: адрес уволившегося бухгалтера отбивается сервером на каждом
 * письме, а репутация отправителя падает для всех писем домена, включая заказы.
 *
 * Жёсткий отказ (адреса не существует) кладёт адрес в стоп-лист; мягкий
 * (ящик переполнен, временная недоступность) — только отмечается в доставке:
 * такой адрес завтра может заработать, и вычёркивать его насовсем нельзя.
 */
class RecordMailBounce
{
    /**
     * Признаки того, что адрес не существует и слать на него бессмысленно.
     */
    private const HARD_MARKERS = [
        'user unknown',
        'no such user',
        'does not exist',
        'mailbox unavailable',
        'invalid recipient',
        'recipient address rejected',
        'address rejected',
        '550',
        '553',
    ];

    public function handleFailure(int $deliveryId, string $error): void
    {
        $delivery = NotificationDelivery::find($deliveryId);

        if ($delivery === null) {
            return;
        }

        $delivery->update([
            'status' => NotificationDelivery::STATUS_FAILED,
            'error' => mb_substr($error, 0, 2000),
        ]);

        if (! $this->isHardBounce($error)) {
            return;
        }

        NotificationSuppression::updateOrCreate(
            ['email' => $delivery->recipient, 'scope' => NotificationSuppression::SCOPE_ALL],
            [
                'reason' => NotificationSuppression::REASON_BOUNCE,
                'contact_id' => $delivery->contact_id,
                'note' => mb_substr($error, 0, 500),
            ],
        );

        Log::warning('Пульт уведомлений: адрес отвергнут почтовым сервером', [
            'recipient' => $delivery->recipient,
            'delivery_id' => $delivery->id,
        ]);

        // Контакт с битым адресом подсвечивается в адресной книге: менеджер
        // видит причину и может обновить адрес.
        if ($delivery->contact_id !== null) {
            ClientContact::query()
                ->whereKey($delivery->contact_id)
                ->update(['notes' => 'Почтовый сервер отвергает адрес — проверьте его актуальность']);
        }
    }

    private function isHardBounce(string $error): bool
    {
        $haystack = mb_strtolower($error);

        foreach (self::HARD_MARKERS as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }
}
