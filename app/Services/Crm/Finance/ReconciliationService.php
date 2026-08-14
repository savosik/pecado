<?php

namespace App\Services\Crm\Finance;

use App\Models\ContractorOrganizationBalance;
use App\Models\SettlementEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Акт сверки взаиморасчётов (v16.0.0, карточка fin-08).
 *
 * Первый экран, отвечающий на вопрос заказчика «сколько клиент оплатил и когда»
 * в том виде, в каком его задаёт бухгалтерия: сальдо на начало, обороты, сальдо
 * на конец. Формула ровно та, что описал заказчик:
 *
 *   Начальное сальдо + Оплаты + Возвраты товара − Реализации − Возврат денег
 *
 * и в ленте движений она сводится к `SUM(amount)` без единого условия — знак
 * уже несёт смысл операции.
 *
 * ## Ось акта — контрагент × организация × валюта
 *
 * Не соглашение и не договор: соглашение 1С берёт из документа-регистратора,
 * и при изменении задним числом группировка исторических движений разъезжается.
 * Соглашение остаётся фильтром внутри акта.
 *
 * Организация обязательна к выбору не из технических соображений: сводный акт
 * по нескольким нашим юрлицам юридически бессмыслен — его нельзя подписать.
 */
class ReconciliationService
{
    /** Потолок строк акта: длиннее его всё равно смотрят в выгрузке. */
    public const MAX_ROWS = 2000;

    /**
     * Дата начала ленты движений. Раньше неё регистр пуст, и это не «нет данных».
     *
     * 31.12.2025, а не 01.01.2026: 1С отдаёт историю целиком, и долг прошлых
     * периодов приезжает документами «Ввод остатков взаиморасчётов» последним
     * днём 2025 года — 772 движения (v16.3.0). С прежней датой акт за период,
     * заканчивающийся 31 декабря, показывал бы «данных нет» при наличии данных.
     */
    public const LEDGER_STARTS_AT = '2025-12-31';

    /** Погрешность сравнения с балансом 1С. */
    private const EPSILON = 0.01;

    /**
     * Акт сверки за период.
     *
     * @return array<string, mixed>
     */
    public function act(
        User $client,
        ?int $organizationId,
        string $from,
        string $to,
        ?int $agreementId = null,
        bool $withoutAgreement = false,
        string $currency = 'RUB',
    ): array {
        $scope = fn () => SettlementEntry::query()
            ->facts()
            ->where('user_id', $client->id)
            ->where('currency_code', $currency)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($agreementId !== null, fn ($query) => $query->where('agreement_id', $agreementId))
            ->when($withoutAgreement, fn ($query) => $query->whereNull('agreement_id'));

        $opening = (float) $scope()->whereDate('date', '<', $from)->sum('amount');

        $entries = $scope()
            ->whereBetween(DB::raw('DATE(date)'), [$from, $to])
            ->orderBy('date')
            ->orderBy('id')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        $truncated = $entries->count() > self::MAX_ROWS;
        $entries = $entries->take(self::MAX_ROWS);

        $balance = $opening;
        $debit = 0.0;
        $credit = 0.0;
        $rows = [];

        foreach ($entries as $entry) {
            $amount = (float) $entry->amount;
            $balance += $amount;
            $debit += (float) $entry->debit;
            $credit += (float) $entry->credit;

            $rows[] = [
                'id' => $entry->id,
                'date' => $entry->date?->toDateString(),
                'date_label' => $entry->date?->format('d.m.Y'),
                'document' => $entry->document_label,
                'document_url' => $this->documentUrl($entry),
                'type' => $entry->type,
                'type_label' => $entry->type_label,
                'debit' => round((float) $entry->debit, 2),
                'credit' => round((float) $entry->credit, 2),
                'balance' => round($balance, 2),
                'agreement_name' => $entry->agreement_name,
                'settlement_object_name' => $entry->settlement_object_name,
                'comment' => $entry->comment,
            ];
        }

        return [
            'rows' => $rows,
            'currency' => $currency,
            'period' => ['from' => $from, 'to' => $to],
            'opening_balance' => round($opening, 2),
            'closing_balance' => round($balance, 2),
            'turnover_debit' => round($debit, 2),
            'turnover_credit' => round($credit, 2),
            'rows_count' => count($rows),
            'truncated' => $truncated,
            // Период целиком раньше начала ленты — это не пустой акт, а отсутствие
            // истории. Разница важная: пустой акт менеджер отправит клиенту.
            'before_ledger' => $to < self::LEDGER_STARTS_AT,
            'ledger_starts_at' => self::LEDGER_STARTS_AT,
            'discrepancy' => $this->discrepancy($client, $organizationId, $currency),
        ];
    }

    /**
     * Расхождение суммы движений с балансом из 1С.
     *
     * Показывается заметным баннером: акт уходит клиенту, и молча отправить
     * цифру, не сходящуюся с учётной системой, нельзя. Приоритет у 1С —
     * сайт предупреждает, но ничего не пересчитывает.
     *
     * @return array<string, mixed>|null null — расхождения нет либо сверять не с чем
     */
    private function discrepancy(User $client, ?int $organizationId, string $currency): ?array
    {
        // Балансы приходят из 1С только в рублях: сверять валютный акт не с чем.
        if ($currency !== 'RUB') {
            return null;
        }

        $ledger = (float) SettlementEntry::query()
            ->facts()
            ->where('user_id', $client->id)
            ->where('currency_code', 'RUB')
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->sum('amount');

        $erp = ContractorOrganizationBalance::query()
            ->where('user_id', $client->id)
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->sum('current_balance');

        $delta = round($ledger - (float) $erp, 2);

        if (abs($delta) <= self::EPSILON) {
            return null;
        }

        return [
            'ledger' => round($ledger, 2),
            'erp' => round((float) $erp, 2),
            'delta' => $delta,
        ];
    }

    /**
     * Ссылка на документ сайта. Её может не быть вовсе: отчёт комиссионера
     * на сайт не приезжает, а реализация могла опоздать за своим движением.
     */
    private function documentUrl(SettlementEntry $entry): ?string
    {
        if ($entry->document_id === null) {
            return null;
        }

        return match ($entry->document_type) {
            \App\Models\Shipment::class => route('crm.shipments.show', $entry->document_id),
            \App\Models\Order::class => route('crm.orders.show', $entry->document_id),
            default => null,
        };
    }

    /**
     * Организации, по которым у клиента вообще есть движения, — для выбора в форме.
     *
     * @return list<array{id: int, name: string}>
     */
    public function organizationsOf(User $client): array
    {
        return SettlementEntry::query()
            ->facts()
            ->where('settlement_entries.user_id', $client->id)
            ->whereNotNull('settlement_entries.organization_id')
            ->join('organizations as o', 'o.id', '=', 'settlement_entries.organization_id')
            ->distinct()
            ->orderBy('o.name')
            ->pluck('o.name', 'o.id')
            ->map(static fn (string $name, int $id): array => ['id' => $id, 'name' => $name])
            ->values()
            ->all();
    }

    /**
     * Соглашения, встречающиеся в движениях клиента.
     *
     * @return list<array{id: int, name: string}>
     */
    public function agreementsOf(User $client): array
    {
        return SettlementEntry::query()
            ->facts()
            ->where('settlement_entries.user_id', $client->id)
            ->whereNotNull('settlement_entries.agreement_id')
            ->join('agreements as a', 'a.id', '=', 'settlement_entries.agreement_id')
            ->distinct()
            ->orderBy('a.name')
            ->pluck('a.name', 'a.id')
            ->map(static fn (?string $name, int $id): array => ['id' => $id, 'name' => $name ?? 'Соглашение #'.$id])
            ->values()
            ->all();
    }

    /**
     * Валюты движений клиента. Акт формируется в одной: складывать рубли с евро
     * в акте, который уходит клиенту, нельзя.
     *
     * @return list<string>
     */
    public function currenciesOf(User $client): array
    {
        $codes = SettlementEntry::query()
            ->facts()
            ->where('user_id', $client->id)
            ->distinct()
            ->pluck('currency_code')
            ->filter()
            ->values()
            ->all();

        return $codes === [] ? ['RUB'] : $codes;
    }

    /**
     * Период по умолчанию: текущий год, но не раньше начала ленты движений.
     *
     * @return array{from: string, to: string}
     */
    public function defaultPeriod(): array
    {
        $today = CarbonImmutable::today();
        $from = $today->startOfYear()->toDateString();

        return [
            'from' => max($from, self::LEDGER_STARTS_AT),
            'to' => $today->toDateString(),
        ];
    }
}
