<?php

namespace App\Services\Erp\Handlers;

use App\Models\Organization;
use App\Models\SettlementDocument;
use App\Models\SettlementEntry;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Support\ResolvesSettlementParties;
use App\Services\Settlements\SettlementProjector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Движения регистра взаиморасчётов, порождённые проведением документа (v16.0.0).
 *
 * **Полная замена, а не дельта.** Сообщение вытесняет все ранее сохранённые
 * фактические движения этого `document_uuid`. Отличить «строка не изменилась»
 * от «строки больше нет» по дельте невозможно, поэтому применяется тот же приём
 * delete-and-recreate, что в `PaymentAllocationService::sync()`
 * и `HandleShipmentCreated::syncItems()`.
 *
 * Все измерения регистра берутся из **строки**, а не из шапки: замер 1С показал,
 * что 33 % документов двигают несколько объектов расчётов, а у 27 документов
 * расходится и ключ аналитики. Шапка читается только как запасной источник —
 * на время, пока 1С шлёт реквизиты по инерции наверху.
 */
class HandleSettlementPosted
{
    use ResolvesSettlementParties;

    /**
     * Измерения, которые ищутся сначала в строке, затем в шапке.
     *
     * @var list<string>
     */
    private const DIMENSION_FIELDS = [
        'partner_uuid',
        'contractor_uuid',
        'organization_uuid',
        'agreement_uuid',
        'agreement_name',
        'contract_uuid',
        'contract_name',
        'settlement_object_kind',
        'settlement_object_uuid',
        'settlement_object_name',
        'tax_id',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $documentUuid = $this->stringOrNull($payload['document_uuid'] ?? null);

        if ($documentUuid === null) {
            throw new ErpUnprocessableMessageException('settlement.posted: отсутствует document_uuid');
        }

        $entries = $payload['entries'] ?? [];

        if (! is_array($entries)) {
            throw new ErpUnprocessableMessageException('settlement.posted: entries не является массивом');
        }

        $rows = [];

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw new ErpUnprocessableMessageException(
                    sprintf('settlement.posted: строка %d не является объектом', $index),
                );
            }

            $rows[] = $this->buildRow($payload, $entry, $documentUuid, $index);
        }

        $rows = $this->rejectExcludedOrganizations($rows, $documentUuid);

        DB::transaction(function () use ($documentUuid, $payload, $rows) {
            $this->rememberDocument($documentUuid, $payload, count($rows));

            // Плановые строки принадлежат payment_schedule.updated и здесь
            // не трогаются: у них свой жизненный цикл и своё сообщение.
            SettlementEntry::query()
                ->where('document_uuid', $documentUuid)
                ->where('nature', SettlementEntry::NATURE_FACT)
                ->delete();

            foreach ($rows as $row) {
                SettlementEntry::query()->create($row);
            }
        });

        app(SettlementProjector::class)->projectDocument($documentUuid);

        Log::info('settlement.posted: движения документа применены', [
            'document_uuid' => $documentUuid,
            'entries' => count($rows),
        ]);
    }

    /**
     * Одна строка регистра из строки сообщения.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function buildRow(array $payload, array $entry, string $documentUuid, int $index): array
    {
        $uuid = $this->stringOrNull($entry['uuid'] ?? null);
        $type = $this->stringOrNull($entry['type'] ?? null);

        if ($uuid === null || $type === null || ! array_key_exists('amount', $entry)) {
            throw new ErpUnprocessableMessageException(
                sprintf('settlement.posted: в строке %d нет uuid, type или amount', $index),
            );
        }

        if (! array_key_exists($type, SettlementEntry::TYPE_LABELS) || $type === SettlementEntry::TYPE_PAYMENT_DUE) {
            // Пропустить строку молча нельзя: баланс разъедется, и никто не заметит.
            // Лучше отправить документ целиком в разбор.
            throw new ErpUnprocessableMessageException(
                sprintf('settlement.posted: неизвестный тип движения «%s» в строке %d', $type, $index),
            );
        }

        $dimensions = [];
        foreach (self::DIMENSION_FIELDS as $field) {
            $dimensions[$field] = $this->stringOrNull($entry[$field] ?? null)
                ?? $this->stringOrNull($payload[$field] ?? null);
        }

        $currency = $this->stringOrNull($entry['currency_code'] ?? null) ?? 'RUB';
        $amount = (float) $entry['amount'];

        // tax_id участвует только в резолве контрагента — своей колонки у него нет.
        $storedDimensions = $dimensions;
        unset($storedDimensions['tax_id']);

        $row = array_merge($this->resolveSettlementParties($dimensions), $storedDimensions, [
            'uuid' => $uuid,
            'revision' => $payload['revision'] ?? null,
            'source' => 'erp',
            'nature' => SettlementEntry::NATURE_FACT,
            'type' => $type,
            'date' => $this->stringOrNull($entry['date'] ?? null)
                ?? $this->stringOrNull($payload['document_date'] ?? null),
            'amount' => $amount,
            'currency_code' => $currency,
            'amount_rub' => $this->resolveAmountRub($entry, $amount, $currency, $uuid),
            // Ресурс «Оплачивается» (v16.4): NULL, если сообщение старее — «нет данных»
            // и «ноль» здесь разные вещи, иначе старые движения выдали бы себя
            // за полностью неоплаченные.
            'paying_amount' => is_numeric($entry['paying_amount'] ?? null)
                ? (float) $entry['paying_amount']
                : null,
            'movement_kind' => $this->stringOrNull($entry['movement_kind'] ?? null),
            'document_uuid' => $documentUuid,
            'document_kind' => $this->stringOrNull($payload['document_kind'] ?? null),
            'document_number' => $this->stringOrNull($payload['document_number'] ?? null),
            'document_date' => $this->stringOrNull($payload['document_date'] ?? null),
            'comment' => $this->stringOrNull($entry['comment'] ?? null),
            'erp_created_at' => $payload['erp_created_at'] ?? null,
            'erp_updated_at' => $payload['erp_updated_at'] ?? null,
        ]);

        if ($row['date'] === null) {
            throw new ErpUnprocessableMessageException(
                sprintf('settlement.posted: у строки %d нет ни своей даты, ни даты документа', $index),
            );
        }

        return $row;
    }

    /**
     * Движения внутренних организаций (например, «Рекламы») в расчёты с клиентами
     * не попадают: они описывают внутренние операции 1С, а не долг контрагента.
     *
     * Фильтр стоит ПОСЛЕ построения строк и ДО транзакции: delete-and-recreate
     * при этом выполняется как обычно, поэтому перепроведение документа заодно
     * вычищает его ранее принятые движения исключённой организации.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rejectExcludedOrganizations(array $rows, string $documentUuid): array
    {
        $excluded = Organization::settlementsExcludedIds();

        if ($excluded === []) {
            return $rows;
        }

        $kept = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! in_array($row['organization_id'], $excluded, true),
        ));

        if (count($kept) !== count($rows)) {
            Log::info('settlement.posted: движения исключённой организации пропущены', [
                'document_uuid' => $documentUuid,
                'skipped' => count($rows) - count($kept),
            ]);
        }

        return $kept;
    }

    /**
     * Рублёвый эквивалент приходит из 1С (`СуммаРегл`) и никогда не считается сайтом.
     *
     * Свой курс подставлять нельзя: справочник валют на сайте обновляется отдельно,
     * и акт сверки, посчитанный по нему, разъедется с отчётами 1С — причём задним
     * числом, потому что курс меняется, а документ нет.
     *
     * @param  array<string, mixed>  $entry
     */
    private function resolveAmountRub(array $entry, float $amount, string $currency, string $uuid): ?float
    {
        if (array_key_exists('amount_rub', $entry) && is_numeric($entry['amount_rub'])) {
            return (float) $entry['amount_rub'];
        }

        if ($currency === 'RUB') {
            return $amount;
        }

        Log::warning('settlement.posted: у валютного движения нет amount_rub, свой курс не подставляем', [
            'uuid' => $uuid,
            'currency_code' => $currency,
        ]);

        return null;
    }

    /**
     * Служебная отметка документа-регистратора: якорь для проверки свежести
     * и признак отмены проведения.
     *
     * @param  array<string, mixed>  $payload
     */
    private function rememberDocument(string $documentUuid, array $payload, int $entriesCount): void
    {
        SettlementDocument::query()->updateOrCreate(
            ['uuid' => $documentUuid],
            [
                'document_kind' => $this->stringOrNull($payload['document_kind'] ?? null),
                'document_number' => $this->stringOrNull($payload['document_number'] ?? null),
                'document_date' => $this->stringOrNull($payload['document_date'] ?? null),
                // Проведение снимает отметку отмены: в 1С перепроведение
                // отменённого документа — обычная операция.
                'is_reverted' => false,
                'last_posted_at' => now(),
            ],
        );

        if ($entriesCount === 0) {
            Log::info('settlement.posted: пустой entries — движения документа удалены', [
                'document_uuid' => $documentUuid,
            ]);
        }
    }
}
