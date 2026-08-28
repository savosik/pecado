<?php

namespace App\Services\Crm\Mail;

use App\Enums\OrderStatus;
use App\Enums\PrintedDocumentType;
use App\Models\CrmEmail;
use App\Models\User;

/**
 * Метки письма — то, за что цепляется правило-фильтр.
 *
 * Метка сравнивается целиком, а не как подстрока: ИНН 7701234567 иначе нашёлся бы
 * внутри 77012345678 и внутри номера заказа, и объяснить менеджеру, почему письмо
 * ушло не туда, было бы нечем.
 *
 * Числа превращаются в ступени (`просрочка:60+`) по той же причине: по подстроке
 * числа фильтровать невозможно, а условие «больше 60 дней» в списке меток выглядит
 * чужеродно. Кому нужен точный порог — числовое условие в правиле.
 */
class MailTagBuilder
{
    /**
     * Метки повода: «про что» письмо.
     *
     * Ключ события → набор меток. Событие, которого здесь нет, получит только
     * метку раздела: правило «всё по этому контрагенту» подхватит его само.
     *
     * @var array<string, array<int, string>>
     */
    private const EVENT_TAGS = [
        'orders.created' => ['заказ', 'новый-заказ'],
        'orders.status_changed' => ['заказ', 'смена-статуса'],
        'orders.items_updated' => ['заказ', 'состав-изменён'],
        'orders.attributes_updated' => ['заказ', 'реквизиты-изменены'],
        'orders.shortfall' => ['заказ', 'недобор'],
        'orders.substitution_offered' => ['заказ', 'замена'],
        'orders.shipped' => ['заказ', 'отгрузка'],
        'documents.published' => ['документы'],
        'documents.deleted' => ['документы', 'документ-отозван'],
        'finance.payment_due_soon' => ['оплата', 'срок-подходит'],
        'finance.overdue_started' => ['оплата', 'просрочка'],
        'finance.overdue_grew' => ['оплата', 'просрочка', 'просрочка-выросла'],
        'finance.overdue_cleared' => ['оплата', 'просрочка-погашена'],
        'finance.debt_overdue' => ['оплата', 'просрочка', 'лестница'],
        'finance.debt_no_preorders' => ['оплата', 'просрочка', 'лестница', 'предзаказы-закрыты'],
        'finance.debt_no_orders' => ['оплата', 'просрочка', 'лестница', 'заказы-закрыты'],
        'finance.debt_hold' => ['оплата', 'просрочка', 'лестница', 'стоп-отгрузка'],
        'finance.debt_cleared' => ['оплата', 'лестница', 'просрочка-погашена'],
        'system.return_created' => ['возврат'],
        'system.return_status_changed' => ['возврат', 'смена-статуса'],
        'system.question_received' => ['вопрос'],
    ];

    /**
     * Тип печатной формы → метка, понятная менеджеру.
     *
     * Технический `reconciliation_act` метка не годится: правило пишет человек,
     * и он ищет «акт-сверки».
     *
     * @var array<string, string>
     */
    private const DOCUMENT_TAGS = [
        'reconciliation_act' => 'акт-сверки',
        'upd' => 'упд',
        'ukd' => 'укд',
        'invoice' => 'счёт',
        'tax_invoice' => 'счёт-фактура',
        'correction_invoice' => 'корректировочный-счёт-фактура',
        'act' => 'акт',
        'waybill' => 'накладная',
        'consignment_note' => 'товарная-накладная',
        'contract' => 'договор',
        'agreement' => 'соглашение',
        'specification' => 'спецификация',
        'price_list' => 'прайс-лист',
    ];

    /**
     * Собрать метки письма.
     *
     * @param  array<string, mixed>  $data  числа повода
     * @return array<int, string>
     */
    public function build(?string $eventKey, array $data, ?User $client = null): array
    {
        $tags = array_merge(
            $this->eventTags($eventKey, $data),
            $this->clientTags($client, $data),
            $this->stepTags($data),
        );

        return array_values(array_unique(array_filter(
            $tags,
            fn (string $tag): bool => trim($tag) !== '',
        )));
    }

    /**
     * Метки письма, написанного менеджером руками.
     *
     * Повода у него нет, поэтому остаются только метки «про кого» — и этого
     * достаточно, чтобы правило «всё по этому контрагенту» его поймало.
     *
     * @return array<int, string>
     */
    public function forManualLetter(?User $client, array $data = []): array
    {
        return $this->build(null, $data + ['manual' => true], $client);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function eventTags(?string $eventKey, array $data): array
    {
        if ($eventKey === null) {
            return ['письмо-менеджера'];
        }

        $tags = self::EVENT_TAGS[$eventKey] ?? [];
        $tags[] = 'раздел:'.explode('.', $eventKey)[0];
        $tags[] = 'повод:'.$eventKey;

        if (filled($data['status'] ?? null)) {
            $status = OrderStatus::tryFrom((string) $data['status']);
            $tags[] = 'статус:'.($status?->label() ?? $data['status']);
        }

        if (($data['has_removed'] ?? false) || (int) ($data['removed_count'] ?? 0) > 0) {
            $tags[] = 'недобор';
        }

        if (filled($data['document_type'] ?? null)) {
            $type = (string) $data['document_type'];
            $tags[] = self::DOCUMENT_TAGS[$type]
                ?? 'документ:'.(PrintedDocumentType::tryFrom($type)?->label() ?? $type);
        }

        if ($data['is_revision'] ?? false) {
            $tags[] = 'перевыставлен';
        }

        if ($data['has_invoice_document'] ?? false) {
            $tags[] = 'есть-счёт';
        }

        return $tags;
    }

    /**
     * Метки «про кого»: контрагент, ИНН, менеджер и данные профиля CRM.
     *
     * Профиль берётся отдельными метками, а не склейкой в простыню: иначе фильтр
     * «Ромашка» поймал бы и клиента «Ромашка», и того, у кого в заметке
     * «раньше работал в Ромашке».
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function clientTags(?User $client, array $data): array
    {
        $tags = [];

        if (filled($data['company_tax_id'] ?? null)) {
            $tags[] = 'инн:'.$data['company_tax_id'];
        }

        if (filled($data['company_name'] ?? null)) {
            $tags[] = 'контрагент:'.$data['company_name'];
        }

        if ($client === null) {
            return $tags;
        }

        $tags[] = 'партнёр:'.$client->display_name;

        if (filled($client->city)) {
            $tags[] = 'город:'.$client->city;
        }

        $profile = $client->relationLoaded('crmProfile') ? $client->crmProfile : $client->crmProfile()->first();

        if ($profile === null) {
            return $tags;
        }

        if ($profile->lifecycle_status !== null) {
            $tags[] = 'стадия:'.$profile->lifecycle_status->label();
        }

        if ($profile->business_type !== null) {
            $tags[] = 'бизнес:'.$profile->business_type->label();
        }

        if ($profile->has_offline_points) {
            $tags[] = 'есть-офлайн-точки';
        }

        if ($profile->works_with_marketplaces) {
            $tags[] = 'маркетплейсы';
        }

        return $tags;
    }

    /**
     * Ступени для чисел: `просрочка:60+`, `сумма:100000+`.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function stepTags(array $data): array
    {
        $tags = [];
        $steps = (array) config('mail_stream.steps', []);

        $days = (int) ($data['days_overdue'] ?? 0);
        foreach ((array) ($steps['просрочка'] ?? []) as $step) {
            if ($days >= (int) $step) {
                $tags[] = 'просрочка:'.$step.'+';
            }
        }

        $amount = (float) ($data['overdue_amount'] ?? $data['amount'] ?? $data['total'] ?? 0);
        foreach ((array) ($steps['сумма'] ?? []) as $step) {
            if ($amount >= (float) $step) {
                $tags[] = 'сумма:'.$step.'+';
            }
        }

        return $tags;
    }

    /**
     * Метки повода без меток клиента — то, за что имеет смысл зацепить
     * правило, когда настраиваешь его по сводке непойманного.
     *
     * @return array<int, string>
     */
    public function occasionTags(string $eventKey): array
    {
        return self::EVENT_TAGS[$eventKey] ?? [];
    }

    /**
     * Все метки, встречавшиеся в потоке за последнее время, — для подсказки
     * в конструкторе правила.
     *
     * @return array<int, string>
     */
    public function suggestions(int $limit = 400): array
    {
        $tags = [];

        CrmEmail::query()
            ->whereNotNull('tags')
            ->latest('id')
            ->limit($limit)
            ->pluck('tags')
            ->each(function ($row) use (&$tags): void {
                // pluck отдаёт значение как есть, если каст не применился, —
                // строка JSON, а не массив.
                if (is_string($row)) {
                    $row = json_decode($row, true) ?: [];
                }

                foreach ((array) $row as $tag) {
                    $tags[(string) $tag] = true;
                }
            });

        $keys = array_keys($tags);
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        return $keys;
    }
}
