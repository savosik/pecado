<?php

namespace App\Services\Crm\Finance;

use App\Models\SettlementCheckpoint;
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
        ?int $companyId = null,
    ): array {
        $scope = fn () => SettlementEntry::query()
            ->facts()
            ->where('user_id', $client->id)
            ->where('currency_code', $currency)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            ->when($agreementId !== null, fn ($query) => $query->where('agreement_id', $agreementId))
            ->when($withoutAgreement, fn ($query) => $query->whereNull('agreement_id'));

        $opening = (float) $scope()->whereDate('date', '<', $from)->sum('amount');

        $entries = $scope()
            // Контрагент и наша организация показываются построчно, пока их не
            // задали фильтром: без них акт «по всем юрлицам» — столбик сумм,
            // о котором нельзя сказать, между кем и кем эти расчёты.
            //
            // withoutGlobalScopes у контрагента: CompanyScope прячет чужие юрлица
            // от пользователя без ролей, и сотрудник, которому права выданы
            // напрямую, видел бы акт с пустой колонкой. Доступ здесь уже решён
            // выше — движения отобраны по партнёру из скоупа актора.
            ->with([
                'company' => fn ($query) => $query->withoutGlobalScopes()->select('id', 'name'),
                'organization:id,name',
            ])
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
                'company_name' => $entry->company?->getAttribute('name'),
                'organization_name' => $entry->organization?->getAttribute('name'),
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
            'discrepancy' => $this->discrepancy($client, $organizationId, $currency, $companyId),
        ];
    }

    /**
     * Расхождение ленты со сверенной контрольной точкой 1С.
     *
     * ## Почему не с `balance.updated` (изменено в v16.3.0)
     *
     * Раньше сверялись с `contractor_organization_balances`, и баннер запрещал
     * отправлять акт. 14.08.2026 сторона 1С сообщила, что канал балансов
     * недостоверен и правка не запланирована: живой пример — Афонина, у которой
     * лента сходится с контрольной точкой копейка в копейку, а `balance.updated`
     * расходится на 53 652,42 ₽. Баннер блокировал корректный акт, и менеджер
     * привыкал его игнорировать — худшее, что может случиться с предупреждением.
     *
     * Контрольная точка приходит из того же регистра, что и лента, поэтому
     * расхождение с ней означает дырку в истории, а не спор двух каналов 1С.
     *
     * Сравнивается состояние НА дату точки: движения после неё в точку не входят.
     *
     * @return array<string, mixed>|null null — расхождения нет либо сверять не с чем
     */
    private function discrepancy(User $client, ?int $organizationId, string $currency, ?int $companyId = null): ?array
    {
        // Контрольные точки приходят из 1С только в рублях.
        if ($currency !== 'RUB') {
            return null;
        }

        $checkpoints = SettlementCheckpoint::query()
            ->where('is_verified', true)
            ->where('user_id', $client->id)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId));

        // Точек может быть несколько на разные даты — берём самую свежую.
        $asOf = (clone $checkpoints)->max('as_of_date');

        if ($asOf === null) {
            return null;
        }

        $asOfDate = CarbonImmutable::parse((string) $asOf)->toDateString();

        $erp = (float) (clone $checkpoints)->whereDate('as_of_date', $asOfDate)->sum('amount');

        $ledger = (float) SettlementEntry::query()
            ->facts()
            ->where('user_id', $client->id)
            ->where('currency_code', 'RUB')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->when($organizationId !== null, fn ($query) => $query->where('organization_id', $organizationId))
            // Строго раньше: точка описывает состояние на начало дня.
            ->whereDate('date', '<', $asOfDate)
            ->sum('amount');

        $delta = round($ledger - $erp, 2);

        if (abs($delta) <= self::EPSILON) {
            return null;
        }

        return [
            'ledger' => round($ledger, 2),
            'erp' => round($erp, 2),
            'delta' => $delta,
            'as_of' => $asOfDate,
            'as_of_label' => CarbonImmutable::parse($asOfDate)->format('d.m.Y'),
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
     * Контрагенты (юрлица клиента), по которым есть движения, — для выбора в форме.
     *
     * Из самих движений, а не из списка компаний клиента: акт по контрагенту
     * без единой операции пуст всегда, и предлагать его выбирать незачем.
     *
     * @return list<array{id: int, name: string}>
     */
    public function companiesOf(User $client): array
    {
        return SettlementEntry::query()
            ->facts()
            ->where('settlement_entries.user_id', $client->id)
            ->whereNotNull('settlement_entries.company_id')
            ->join('companies as c', 'c.id', '=', 'settlement_entries.company_id')
            // Join в обход модели, поэтому SoftDeletes отфильтровываем руками.
            ->whereNull('c.deleted_at')
            ->distinct()
            ->orderBy('c.name')
            ->pluck('c.name', 'c.id')
            ->map(static fn (string $name, int $id): array => ['id' => $id, 'name' => $name])
            ->values()
            ->all();
    }

    /**
     * Контрагенты всего доступного менеджеру круга — для выбора без клиента.
     *
     * Контрагента выбирают первым не реже, чем клиента: в переписке о сверке
     * фигурирует юрлицо («пришлите акт по ООО „Ромашка“»), а под каким партнёром
     * оно заведено на сайте — знание, которого у менеджера в этот момент нет.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $clients
     * @return list<array{id: int, name: string}>
     */
    public function companiesInScope(\Illuminate\Database\Eloquent\Builder $clients): array
    {
        return SettlementEntry::query()
            ->facts()
            ->whereIn('settlement_entries.user_id', (clone $clients))
            ->whereNotNull('settlement_entries.company_id')
            ->join('companies as c', 'c.id', '=', 'settlement_entries.company_id')
            ->whereNull('c.deleted_at')
            ->distinct()
            ->orderBy('c.name')
            ->pluck('c.name', 'c.id')
            ->map(static fn (string $name, int $id): array => ['id' => $id, 'name' => $name])
            ->values()
            ->all();
    }

    /**
     * Наши организации всего доступного круга — для выбора без клиента.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $clients
     * @return list<array{id: int, name: string}>
     */
    public function organizationsInScope(\Illuminate\Database\Eloquent\Builder $clients): array
    {
        return SettlementEntry::query()
            ->facts()
            ->whereIn('settlement_entries.user_id', (clone $clients))
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
     * Партнёр, которому принадлежит контрагент, — по его же движениям.
     *
     * Акт строится по партнёру: расчёты ведутся с юрлицом, но карточка, скоуп
     * и права живут на партнёре. Поэтому выбор одного контрагента доводится
     * до партнёра здесь, а не требует второго выбора от менеджера.
     *
     * Если одно юрлицо встречается у нескольких партнёров (в 1С так бывает
     * после переноса карточек), возвращается null: угадывать, чей это акт,
     * нельзя — менеджер уточнит партнёра сам.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $clients
     */
    public function clientOfCompany(\Illuminate\Database\Eloquent\Builder $clients, int $companyId): ?User
    {
        $ids = SettlementEntry::query()
            ->facts()
            ->whereIn('user_id', (clone $clients))
            ->where('company_id', $companyId)
            ->distinct()
            ->limit(2)
            ->pluck('user_id')
            ->all();

        return count($ids) === 1 ? User::query()->find($ids[0]) : null;
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
