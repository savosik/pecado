<?php

namespace App\Services\Erp\Support;

/**
 * Раскладка payload платежа по колонкам `payments`.
 *
 * Общий для `payment.created` и `payment.updated`: структура сообщений одинакова,
 * и расхождение маппинга между ними означало бы, что перепроведение документа
 * молча теряет часть реквизитов.
 *
 * Возвращаются **только присутствующие в payload ключи**. Это позволяет `updated`
 * обновлять шапку частично, не сбрасывая то, что 1С не прислала. Явный `null`
 * при этом остаётся операцией — отличается от отсутствия ключа.
 */
class PaymentPayloadMapper
{
    /**
     * Прямые соответствия «ключ payload → колонка».
     *
     * @var array<string, string>
     */
    private const DIRECT_FIELDS = [
        'number' => 'number',
        'date' => 'date',
        'operation_code' => 'operation_code',
        'operation_name' => 'operation_name',
        'document_type' => 'document_type',
        'bank_number' => 'bank_number',
        'bank_date' => 'bank_date',
        'bank_confirmed_at' => 'bank_confirmed_at',
        'uip' => 'uip',
        'purpose' => 'purpose',
        // v15.16.0: комментарий 1С кладём в отдельную колонку. `payments.comment`
        // принадлежит сайту — это единственное поле платежа, которое ведёт
        // сотрудник, и общая колонка стирала бы его заметку при каждой доставке.
        'comment' => 'erp_comment',
        'amount' => 'amount',
        'currency_code' => 'currency_code',
        'erp_created_at' => 'erp_created_at',
        'erp_updated_at' => 'erp_updated_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function fields(array $payload): array
    {
        $fields = [];

        foreach (self::DIRECT_FIELDS as $key => $column) {
            if (array_key_exists($key, $payload)) {
                $fields[$column] = $payload[$key];
            }
        }

        // Направление: сайт не выводит его из текста операции — наименование
        // меняется вместе с настройками учёта. Неизвестное значение трактуем
        // как поступление, чтобы деньги не потерялись из отчётов.
        if (array_key_exists('direction', $payload)) {
            $fields['direction'] = $payload['direction'] === 'out' ? 'out' : 'in';
        }

        if (array_key_exists('bank_confirmed', $payload)) {
            $fields['bank_confirmed'] = (bool) $payload['bank_confirmed'];
        }

        if (array_key_exists('contractor_uuid', $payload)) {
            // Хранится всегда, даже когда Company не резолвится: по нему связь
            // доклеивается, когда контрагент приедет из 1С.
            $fields['contractor_uuid'] = $payload['contractor_uuid'];
        }

        if (array_key_exists('tax_id', $payload)) {
            $fields['tax_id'] = $payload['tax_id'];
        }

        $fields += self::accountFields($payload, 'organization_account', 'organization_account', 'organization_bank_name');
        $fields += self::accountFields($payload, 'payer_account', 'payer_account', 'payer_bank_name');

        return $fields;
    }

    /**
     * Банковский счёт из payload разложить в пару колонок.
     *
     * @return array<string, mixed>
     */
    private static function accountFields(array $payload, string $key, string $numberColumn, string $bankColumn): array
    {
        if (! array_key_exists($key, $payload)) {
            return [];
        }

        $account = $payload[$key];

        if (! is_array($account)) {
            return [$numberColumn => null, $bankColumn => null];
        }

        return [
            $numberColumn => $account['number'] ?? null,
            $bankColumn => $account['bank_name'] ?? null,
        ];
    }
}
