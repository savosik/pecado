<?php

namespace App\Console\Commands;

use App\Models\Agreement;
use App\Models\Company;
use App\Models\ContractorOrganizationBalance;
use App\Models\Organization;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementDocument;
use App\Models\SettlementEntry;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Правдоподобная лента взаиморасчётов для dev-стенда.
 *
 * Регистр пуст до первой выгрузки из 1С, а значит все экраны эпика — акт сверки,
 * план на регистре, плитка фактического баланса — существуют только в тестах.
 * Команда наполняет стенд так, чтобы их можно было открыть и посмотреть.
 *
 * ## Данные согласованные, а не случайные
 *
 * Балансы и контрольные точки считаются ИЗ сгенерированных движений, а не рядом
 * с ними. Иначе `settlements:verify` показал бы расхождение, которого в реальности
 * нет, и первое же знакомство с инструментом свелось бы к разбору собственного мусора.
 *
 * Проверка после прогона обязана быть зелёной — это и есть главный смысл упражнения:
 * убедиться, что сверка сходится на непустых данных до того, как приедет 1С.
 *
 * ## Почему не фабрики и не сидер
 *
 * Сидер соблазнительно запустить на проде вместе с остальными. Команда с явным
 * запретом на production такой возможности не даёт, а `--fresh` позволяет
 * перегенерировать стенд, не выковыривая строки руками.
 */
class SeedSettlementDemo extends Command
{
    protected $signature = 'settlements:demo
        {--clients=8 : Сколько партнёров наполнить}
        {--fresh : Сначала удалить ранее сгенерированное}
        {--force : Разрешить запуск вне local/dev (не используйте)}';

    protected $description = 'Демо-данные регистра взаиморасчётов для dev-стенда';

    /** Метка сгенерированных строк: по ней и чистим. */
    private const SOURCE = 'demo';

    /** Долг прошлых периодов: лента начинается до 2026 года, как у 1С. */
    private const OPENING_DATE = '2025-12-31';

    /** Дата сверенной контрольной точки. */
    private const CHECKPOINT_DATE = '2026-08-01';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('На проде демо-данные не генерируются. Это боевые взаиморасчёты.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->purge();
        }

        $organizations = $this->organizations();

        $clients = $this->clients((int) $this->option('clients'));

        if ($clients->isEmpty()) {
            $this->error('Не нашлось партнёров с контрагентами. Демо строится на существующих клиентах.');

            return self::FAILURE;
        }

        $this->withProgressBar($clients, function (User $client) use ($organizations): void {
            DB::transaction(fn () => $this->seedClient($client, $organizations->random()));
        });

        $this->newLine(2);
        $this->info('Готово. Дальше:');
        $this->line('  php artisan settlements:stats    — что получилось');
        $this->line('  php artisan settlements:verify   — сверка обязана быть зелёной');

        return self::SUCCESS;
    }

    /**
     * Наши юрлица. На пустом стенде их нет вовсе — организации заводятся
     * в админке руками, и на dev этого никто не делал.
     *
     * Требовать ручной подготовки от команды, которая существует ради «запустил
     * и посмотрел», значило бы обессмыслить её: первый же запуск упирался бы
     * в инструкцию. Поэтому недостающие создаём сами и помечаем в названии.
     *
     * @return \Illuminate\Support\Collection<int, Organization>
     */
    private function organizations(): \Illuminate\Support\Collection
    {
        $existing = Organization::query()->where('is_stub', false)->take(2)->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $this->line('Юрлиц на стенде нет — создаю два демонстрационных.');

        return collect(['ООО «Пекадо» (демо)', 'ИП Демидов (демо)'])
            ->map(fn (string $name): Organization => Organization::query()->create([
                'external_id' => (string) Str::uuid(),
                'name' => $name,
                'legal_name' => $name,
                'tax_id' => (string) random_int(1000000000, 9999999999),
                'tax_code' => (string) random_int(100000000, 999999999),
                'is_active' => true,
                'is_stub' => false,
            ]));
    }

    /**
     * Партнёры с контрагентом: без контрагента строка регистра не встанет
     * на ось сверки, и демо показало бы вырожденный случай.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function clients(int $limit): \Illuminate\Support\Collection
    {
        return User::query()
            ->whereHas('companies')
            ->whereNotNull('personal_manager_id')
            ->with(['companies' => fn ($q) => $q->limit(1)])
            ->take($limit)
            ->get();
    }

    private function seedClient(User $client, Organization $organization): void
    {
        /** @var Company $company */
        $company = $client->companies->first();

        $agreement = $this->agreement($client, $company, $organization);
        $balance = $this->openingBalance($client, $company, $organization, $agreement);

        // До контрольной точки и после неё. Разделение нужно, чтобы инвариант
        // «сумма ленты до даты точки = сумма точки» проверялся на данных, а не на нуле.
        $balance += $this->documents($client, $company, $organization, $agreement, '2026-02-01', 3);
        $checkpointAmount = $balance;

        $balance += $this->documents($client, $company, $organization, $agreement, '2026-08-05', 4);

        $this->checkpoint($client, $company, $organization, $checkpointAmount);
        $this->projectBalance($client, $company, $organization, $balance);
    }

    private function agreement(User $client, Company $company, Organization $organization): Agreement
    {
        return Agreement::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'contractor_uuid' => $company->erp_id,
            'number' => 'СГ-'.str_pad((string) $client->id, 4, '0', STR_PAD_LEFT),
            'date' => '2025-06-01',
            'name' => 'Соглашение об условиях продаж (демо)',
            'currency_code' => 'RUB',
            'settlement_procedure' => 'orders',
            'deferral_days' => 30,
            'status' => Agreement::STATUS_ACTIVE,
        ]);
    }

    /**
     * Долг прошлых периодов — обычным движением, а не строкой `opening_balance`.
     *
     * 1С отдаёт ленту целиком, с 2025 года, и отдельного начального сальдо в ней
     * нет (v16.3.0). Демо-данные обязаны повторять боевую форму, иначе на них
     * не воспроизведётся то, что случается на проде.
     */
    private function openingBalance(User $client, Company $company, Organization $organization, Agreement $agreement): float
    {
        $amount = -1 * random_int(20, 200) * 1000;

        $this->entry($client, $company, $organization, $agreement, [
            'type' => SettlementEntry::TYPE_ADJUSTMENT,
            'amount' => $amount,
            'date' => self::OPENING_DATE,
            'document_kind' => 'other',
            'comment' => 'Долг прошлых периодов на '.self::OPENING_DATE,
        ]);

        return (float) $amount;
    }

    /**
     * Пачка документов: реализация, частичная оплата и график по ней.
     *
     * Возвращает вклад в сальдо — вызывающий складывает его сам, чтобы баланс
     * и контрольная точка считались из тех же чисел, что легли в ленту.
     */
    private function documents(
        User $client,
        Company $company,
        Organization $organization,
        Agreement $agreement,
        string $from,
        int $count,
    ): float {
        $delta = 0.0;
        $date = Carbon::parse($from);

        for ($i = 0; $i < $count; $i++) {
            $date = $date->copy()->addDays(random_int(5, 20));
            $total = random_int(15, 120) * 1000;
            $paid = random_int(0, 10) > 3 ? (int) ($total * (random_int(3, 10) / 10)) : 0;

            $shipment = $this->shipment($client, $company, $organization, $date, $total);

            $this->entry($client, $company, $organization, $agreement, [
                'type' => SettlementEntry::TYPE_SHIPMENT,
                'amount' => -$total,
                'date' => $date->toDateString(),
                'document_uuid' => $shipment->uuid,
                'document_kind' => 'shipment',
                'document_number' => $shipment->number,
                'document_date' => $date->toDateString(),
                'settlement_object_kind' => 'shipment',
                'settlement_object_name' => 'Реализация '.$shipment->number,
            ]);
            $delta -= $total;

            if ($paid > 0) {
                $paidAt = $date->copy()->addDays(random_int(1, 25));

                $this->entry($client, $company, $organization, $agreement, [
                    'type' => SettlementEntry::TYPE_PAYMENT_IN,
                    'amount' => $paid,
                    'date' => $paidAt->toDateString(),
                    'document_uuid' => (string) Str::uuid(),
                    'document_kind' => 'payment',
                    'document_number' => '29УТ-'.random_int(100000, 999999),
                    'document_date' => $paidAt->toDateString(),
                    'movement_kind' => 'expense',
                ]);
                $delta += $paid;
            }

            // Плановая строка: часть просрочена, часть впереди — иначе не видно
            // ни корзин давности, ни календаря.
            $this->entry($client, $company, $organization, $agreement, [
                'nature' => SettlementEntry::NATURE_PLAN,
                'type' => SettlementEntry::TYPE_PAYMENT_DUE,
                'amount' => $total,
                'settled_amount' => $paid,
                'date' => $date->copy()->addDays(30)->toDateString(),
                'document_uuid' => $shipment->uuid,
                'document_kind' => 'shipment',
                'document_number' => $shipment->number,
                'document_date' => $date->toDateString(),
                'line_number' => 1,
                'meta' => ['stage_name' => 'Оплата после отгрузки', 'percent' => 100],
            ]);
        }

        return $delta;
    }

    private function shipment(User $client, Company $company, Organization $organization, Carbon $date, int $total): Shipment
    {
        $shipment = Shipment::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'number' => 'ДЕМО-'.random_int(100000, 999999),
            'date' => $date->toDateString(),
            'erp_created_at' => $date->toDateString(),
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
            'paid_amount' => 0,
        ]);

        SettlementDocument::query()->create([
            'uuid' => $shipment->uuid,
            'applied_revision' => 1,
            'document_kind' => 'shipment',
            'document_number' => $shipment->number,
            'document_date' => $date->toDateString(),
            'last_posted_at' => now(),
        ]);

        return $shipment;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(User $client, Company $company, Organization $organization, Agreement $agreement, array $attributes): void
    {
        SettlementEntry::query()->create($attributes + [
            'uuid' => (string) Str::uuid(),
            'source' => self::SOURCE,
            'nature' => SettlementEntry::NATURE_FACT,
            'user_id' => $client->id,
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'agreement_id' => $agreement->id,
            'contractor_uuid' => $company->erp_id,
            'organization_uuid' => $organization->external_id,
            'agreement_uuid' => $agreement->uuid,
            'agreement_name' => $agreement->name,
            'currency_code' => 'RUB',
            'amount_rub' => $attributes['amount'] ?? 0,
        ]);
    }

    private function checkpoint(User $client, Company $company, Organization $organization, float $amount): void
    {
        SettlementCheckpoint::query()->create([
            'user_id' => $client->id,
            'company_id' => $company->id,
            'organization_id' => $organization->id,
            'contractor_uuid' => (string) $company->erp_id,
            'organization_uuid' => (string) $organization->external_id,
            'currency_code' => 'RUB',
            'as_of_date' => self::CHECKPOINT_DATE,
            'amount' => round($amount, 2),
            'amount_rub' => round($amount, 2),
            'is_verified' => true,
        ]);
    }

    /**
     * Баланс от «1С» — ровно сумма движений. Разойдись он хоть на копейку,
     * сверка показала бы расхождение, которого в реальности нет.
     */
    private function projectBalance(User $client, Company $company, Organization $organization, float $balance): void
    {
        ContractorOrganizationBalance::query()->updateOrCreate(
            ['company_id' => $company->id, 'organization_id' => $organization->id],
            [
                'user_id' => $client->id,
                'current_balance' => round($balance, 2),
                'overdue_debt' => 0,
                'balance_erp_updated_at' => now(),
            ],
        );
    }

    /**
     * Чистка по метке источника. Реализации узнаются по номеру: своей метки
     * у документа нет, а заводить её ради демо в боевой таблице незачем.
     */
    private function purge(): void
    {
        $uuids = SettlementEntry::query()->where('source', self::SOURCE)
            ->whereNotNull('document_uuid')->distinct()->pluck('document_uuid');

        $removed = SettlementEntry::query()->where('source', self::SOURCE)->delete();
        SettlementDocument::query()->whereIn('uuid', $uuids)->delete();
        Shipment::query()->where('number', 'like', 'ДЕМО-%')->forceDelete();
        Agreement::query()->where('name', 'like', '%(демо)')->forceDelete();
        SettlementCheckpoint::query()->whereDate('as_of_date', self::CHECKPOINT_DATE)->delete();
        // Организации — последними: на них ссылаются удалённые выше документы.
        Organization::query()->where('name', 'like', '%(демо)')->forceDelete();

        $this->line(sprintf('Удалено ранее сгенерированных движений: %d', $removed));
    }
}
