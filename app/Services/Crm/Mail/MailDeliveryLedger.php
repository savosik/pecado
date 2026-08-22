<?php

namespace App\Services\Crm\Mail;

use App\Models\CrmEmail;
use App\Models\CrmEmailDelivery;

/**
 * Реестр отправок: кому какое письмо уже уходило.
 *
 * Единственная гарантия, которую он даёт: письмо с одним и тем же id не уйдёт
 * на один и тот же адрес дважды.
 *
 * Слой отдельный намеренно. Правила-фильтры независимы и знать друг о друге
 * не должны — два фильтра могут поймать одно письмо и назвать один и тот же
 * адрес, и это нормальная настройка. Ненормально, если клиент получит два
 * одинаковых письма, и разбираться с этим должно одно место, а не каждое
 * правило по отдельности.
 *
 * Адрес занимается **до** отправки, а не после. Иначе падение между сдачей
 * письма транспорту и записью результата даёт повтор при следующей попытке
 * задания — то есть ровно то, от чего этот слой и заведён. Цена решения:
 * при таком падении письмо может не уйти вовсе, и это видно в карточке письма.
 */
class MailDeliveryLedger
{
    /**
     * Занять адреса под отправку.
     *
     * Возвращает только те, что заняты этим вызовом: остальным письмо уже
     * уходило. Пустой массив означает «отправлять некому, всё уже ушло».
     *
     * @param  array<int, string>  $recipients
     * @return array<int, string> адреса в исходном написании
     */
    public function claim(CrmEmail $email, array $recipients): array
    {
        $normalized = [];

        foreach ($recipients as $recipient) {
            $address = trim((string) $recipient);

            if ($address === '') {
                continue;
            }

            $normalized[mb_strtolower($address)] = $address;
        }

        if ($normalized === []) {
            return [];
        }

        $now = now();
        $claimed = [];

        foreach ($normalized as $key => $address) {
            // По одному адресу за запрос: insertOrIgnore возвращает число
            // вставленных строк, и единица здесь — точный ответ «занял я»,
            // без гонки с соседним воркером. Адресов в письме единицы,
            // так что цена вопроса — несколько запросов.
            $inserted = CrmEmailDelivery::query()->insertOrIgnore([
                'crm_email_id' => $email->getKey(),
                'recipient' => $key,
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                $claimed[] = $address;
            }
        }

        return $claimed;
    }

    /**
     * Отметить, что письмо действительно сдано транспорту.
     *
     * @param  array<int, string>  $recipients
     */
    public function markSent(CrmEmail $email, array $recipients): void
    {
        if ($recipients === []) {
            return;
        }

        CrmEmailDelivery::query()
            ->where('crm_email_id', $email->getKey())
            ->whereIn('recipient', array_map(
                fn (string $recipient): string => mb_strtolower(trim($recipient)),
                $recipients,
            ))
            ->update(['sent_at' => now()]);
    }

    /**
     * Кому это письмо уже уходило — для карточки письма.
     *
     * @return array<int, string>
     */
    public function delivered(CrmEmail $email): array
    {
        return CrmEmailDelivery::query()
            ->where('crm_email_id', $email->getKey())
            ->whereNotNull('sent_at')
            ->pluck('recipient')
            ->all();
    }
}
