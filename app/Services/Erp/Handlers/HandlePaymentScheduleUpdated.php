<?php

namespace App\Services\Erp\Handlers;

use App\Models\Organization;
use App\Models\SettlementDocument;
use App\Models\SettlementEntry;
use App\Services\Erp\Exceptions\ErpUnprocessableMessageException;
use App\Services\Erp\Support\ResolvesSettlementParties;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * График оплаты документа с остатками (v16.0.0).
 *
 * График переехал из массива внутри реализации в собственное событие ради одного
 * поля — `settled_amount`. Раньше 1С присылала только план, а факт сайт сопоставлял
 * сам, методом FIFO по плановой дате. Это не работало: 1С разносит на заказы почти
 * половину денег, гасит долг авансами и зачётами, и самодельная раскладка ошибалась
 * в разы — у одного клиента 5,53 млн ₽ просрочки против 478 тыс ₽ по данным учёта.
 *
 * **Теперь сайт не раскладывает платежи. Ни FIFO, ни как-либо ещё.**
 *
 * ## Реализации и заказы устроены по-разному
 *
 * По реализациям остаток приходит построчно из регистра `РасчетыСКлиентамиПоСрокам`.
 * По заказам его там нет вовсе — в регистре ноль строк с объектом расчётов «заказ
 * клиента», потому что он отвечает на вопрос «сколько осталось закрыть по отгруженному»,
 * а не «сколько клиент должен внести вперёд». Поэтому по заказам приходит одна
 * авторитетная сумма на документ.
 *
 * Она делится по этапам **только для календаря** и помечается `is_settled_derived`.
 * Это не возврат к прежней болезни: прежний механизм раскладывал платежи МЕЖДУ
 * документами, и ошибка накапливалась. Здесь авторитетное число делится ВНУТРИ
 * одного заказа — итог по документу верен по построению, неточной может быть
 * только дата внутри него, а на баланс это не влияет вовсе.
 */
class HandlePaymentScheduleUpdated
{
    use ResolvesSettlementParties;

    /**
     * Реквизиты строки, которые нужны календарю, но не нужны деньгам.
     *
     * Держим в JSON, а не шестью колонками: они существуют только ради подписи
     * «30 % от даты отгрузки» в интерфейсе.
     *
     * @var list<string>
     */
    private const META_FIELDS = ['percent', 'term_days', 'basis', 'basis_name', 'stage', 'stage_name'];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(array $payload): void
    {
        $documentUuid = $this->stringOrNull($payload['document_uuid'] ?? null);
        $documentKind = $this->stringOrNull($payload['document_kind'] ?? null);

        if ($documentUuid === null || $documentKind === null) {
            throw new ErpUnprocessableMessageException(
                'payment_schedule.updated: отсутствует document_uuid или document_kind',
            );
        }

        $lines = $payload['lines'] ?? [];

        if (! is_array($lines)) {
            throw new ErpUnprocessableMessageException('payment_schedule.updated: lines не является массивом');
        }

        $rows = $this->buildRows($payload, $lines, $documentUuid, $documentKind);

        DB::transaction(function () use ($documentUuid, $payload, $rows) {
            SettlementDocument::query()->updateOrCreate(
                ['uuid' => $documentUuid],
                [
                    'document_kind' => $this->stringOrNull($payload['document_kind'] ?? null),
                    'document_number' => $this->stringOrNull($payload['document_number'] ?? null),
                    'document_date' => $this->stringOrNull($payload['document_date'] ?? null),
                ],
            );

            // Фактические движения принадлежат settlement.posted и здесь не трогаются.
            SettlementEntry::query()
                ->where('document_uuid', $documentUuid)
                ->where('nature', SettlementEntry::NATURE_PLAN)
                ->delete();

            foreach ($rows as $row) {
                SettlementEntry::query()->create($row);
            }
        });

        Log::info('payment_schedule.updated: график документа применён', [
            'document_uuid' => $documentUuid,
            'document_kind' => $documentKind,
            'lines' => count($rows),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $lines
     * @return list<array<string, mixed>>
     */
    private function buildRows(array $payload, array $lines, string $documentUuid, string $documentKind): array
    {
        $parties = $this->resolveSettlementParties($payload);

        // График внутренней организации (например, «Рекламы») клиенту не показываем.
        // Пустой список строк не короткое замыкание: транзакция в handle() всё равно
        // выполнится и удалит ранее принятые плановые строки этого документа.
        if (in_array($parties['organization_id'], Organization::settlementsExcludedIds(), true)) {
            Log::info('payment_schedule.updated: график исключённой организации пропущен', [
                'document_uuid' => $documentUuid,
                'organization_id' => $parties['organization_id'],
            ]);

            return [];
        }

        $currency = $this->stringOrNull($payload['currency_code'] ?? null) ?? 'RUB';
        $derived = $this->derivedSettlements($payload, $lines, $documentKind);

        $rows = [];

        foreach ($lines as $index => $line) {
            if (! is_array($line)) {
                throw new ErpUnprocessableMessageException(
                    sprintf('payment_schedule.updated: строка %d не является объектом', $index),
                );
            }

            $uuid = $this->stringOrNull($line['uuid'] ?? null);
            $dueDate = $this->stringOrNull($line['due_date'] ?? null);

            if ($uuid === null || ! array_key_exists('amount', $line)) {
                throw new ErpUnprocessableMessageException(
                    sprintf('payment_schedule.updated: в строке %d нет uuid или amount', $index),
                );
            }

            if ($dueDate === null) {
                // Строка без плановой даты для календаря бессмысленна. Роняем не всё
                // сообщение, а только её: остальной график полезен.
                Log::warning('payment_schedule.updated: строка без due_date пропущена', [
                    'document_uuid' => $documentUuid,
                    'line_uuid' => $uuid,
                ]);

                continue;
            }

            $meta = [];
            foreach (self::META_FIELDS as $field) {
                if (array_key_exists($field, $line) && $line[$field] !== null) {
                    $meta[$field] = $line[$field];
                }
            }

            $rows[] = array_merge($parties, [
                'uuid' => $uuid,
                'revision' => $payload['revision'] ?? null,
                'source' => 'erp',
                'nature' => SettlementEntry::NATURE_PLAN,
                'type' => SettlementEntry::TYPE_PAYMENT_DUE,
                'date' => $dueDate,
                // Суммы графика положительные: это «сколько клиент должен заплатить»,
                // а не движение баланса. Знак здесь смысла не несёт.
                'amount' => abs((float) $line['amount']),
                'currency_code' => $currency,
                'settled_amount' => $derived[$index] ?? $this->lineSettled($line),
                'is_settled_derived' => array_key_exists($index, $derived),
                'document_settled_amount' => is_numeric($payload['document_settled_amount'] ?? null)
                    ? (float) $payload['document_settled_amount']
                    : null,
                'partner_uuid' => $this->stringOrNull($payload['partner_uuid'] ?? null),
                'contractor_uuid' => $this->stringOrNull($payload['contractor_uuid'] ?? null),
                'organization_uuid' => $this->stringOrNull($payload['organization_uuid'] ?? null),
                'agreement_uuid' => $this->stringOrNull($payload['agreement_uuid'] ?? null),
                'document_uuid' => $documentUuid,
                'document_kind' => $documentKind,
                'document_number' => $this->stringOrNull($payload['document_number'] ?? null),
                'document_date' => $this->stringOrNull($payload['document_date'] ?? null),
                'line_number' => is_numeric($line['line_number'] ?? null) ? (int) $line['line_number'] : null,
                'meta' => $meta === [] ? null : $meta,
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $line
     */
    private function lineSettled(array $line): float
    {
        return is_numeric($line['settled_amount'] ?? null) ? (float) $line['settled_amount'] : 0.0;
    }

    /**
     * Разнесение оплаты заказа по этапам — производная величина только для календаря.
     *
     * Возвращает погашенные суммы по индексам строк; пустой массив означает, что
     * разносить нечего и `settled_amount` берётся из самих строк.
     *
     * Порядок — по плановой дате: раньше срок, раньше и закрывается. Итог по заказу
     * верен по построению, потому что делится авторитетное число, а остаток
     * не «размазывается» на чужие документы.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $lines
     * @return array<int, float>
     */
    private function derivedSettlements(array $payload, array $lines, string $documentKind): array
    {
        if ($documentKind !== 'order' || ! is_numeric($payload['document_settled_amount'] ?? null)) {
            return [];
        }

        $remaining = (float) $payload['document_settled_amount'];

        if ($remaining <= 0.0) {
            return [];
        }

        $order = [];
        foreach ($lines as $index => $line) {
            if (is_array($line) && $this->stringOrNull($line['due_date'] ?? null) !== null) {
                $order[$index] = (string) $line['due_date'];
            }
        }

        asort($order);

        $settled = [];

        foreach (array_keys($order) as $index) {
            $amount = is_numeric($lines[$index]['amount'] ?? null) ? abs((float) $lines[$index]['amount']) : 0.0;
            $applied = min($remaining, $amount);

            $settled[$index] = round($applied, 2);
            $remaining = round($remaining - $applied, 2);
        }

        // Переплата по документу оседает на последней строке: она легитимна,
        // а терять её нельзя — иначе календарь покажет долг там, где его нет.
        if ($remaining > 0.0 && $settled !== []) {
            $last = array_key_last($order);
            $settled[$last] = round($settled[$last] + $remaining, 2);
        }

        return $settled;
    }
}
