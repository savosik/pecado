<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\ContractorOrganizationBalance;
use App\Models\Organization;
use App\Models\SettlementCheckpoint;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Приёмочный гейт волны 3 (v16.0.0, карточка fin-06).
 *
 * Команда обязана давать ненулевой код возврата при любом расхождении: гейт,
 * который надо читать глазами, рано или поздно прочитают невнимательно.
 */
class VerifySettlementsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
        $this->organization = Organization::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function entry(array $attributes): SettlementEntry
    {
        return SettlementEntry::factory()->create($attributes + [
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'date' => '2026-03-01',
        ]);
    }

    private function checkpoint(float $amount, string $asOf = '2026-08-01'): void
    {
        SettlementCheckpoint::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'as_of_date' => $asOf,
            'amount' => $amount,
            'is_verified' => true,
        ]);
    }

    private function balance(float $amount): void
    {
        ContractorOrganizationBalance::query()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'current_balance' => $amount,
            'overdue_debt' => 0,
        ]);
    }

    #[Test]
    public function сошедшийся_регистр_даёт_нулевой_код(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000]);
        $this->entry(['type' => SettlementEntry::TYPE_PAYMENT_IN, 'amount' => 65000]);
        $this->balance(-55000);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Расхождение с `balance.updated` команду больше НЕ роняет.
     *
     * 1С признала канал балансов недостоверным (круг 8, 14.08.2026) и правку
     * не запланировала. Гейт на источнике, который сама 1С считает сломанным,
     * означал бы бессрочное ожидание чужой задачи.
     */
    #[Test]
    public function расхождение_с_балансом_1с_команду_не_роняет(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000]);
        $this->balance(-55000);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * …но `--strict-balances` прежнее поведение возвращает: когда канал балансов
     * починят, флаг снова сделает расхождение блокером.
     */
    #[Test]
    public function строгий_режим_балансов_роняет_команду(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000]);
        $this->balance(-55000);

        $this->artisan('settlements:verify --strict-balances')->assertExitCode(1);
    }

    /**
     * Перепутанный знак валидацией схемы не ловится: суммы правильные, а баланс
     * инвертирован у всей базы. Эта проверка — единственный способ его найти.
     */
    #[Test]
    public function инвертированный_знак_реализации_обнаруживается(): void
    {
        // Реализация с плюсом: 1С применила инверсию не в ту сторону.
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => 120000]);
        $this->balance(120000);

        $this->artisan('settlements:verify')
            ->expectsOutputToContain('Знак движения соответствует типу')
            ->assertExitCode(1);
    }

    #[Test]
    public function инвертированный_знак_поступления_обнаруживается(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_PAYMENT_IN, 'amount' => -65000]);
        $this->balance(-65000);

        $this->artisan('settlements:verify')->assertExitCode(1);
    }

    /**
     * Баланс без единого движения — не расхождение данных, а рассинхрон каналов
     * на стороне 1С: `balance.updated` не смотрит список выведенных из обмена
     * контрагентов. Держать это в общем счётчике значит месяцами смотреть
     * на красное там, где всё правильно.
     */
    #[Test]
    public function баланс_без_движений_не_считается_расхождением(): void
    {
        $this->balance(-3_000_000);

        $this->artisan('settlements:verify')
            ->expectsOutputToContain('балансы без движений')
            ->assertExitCode(0);
    }

    /**
     * Внутри одного документа законно встречается строка обратного знака:
     * в отчёте комиссионера на −5 196 приезжала строка +31,32. Построчная
     * проверка объявляла это инверсией и уводила разбор в ложный след.
     */
    #[Test]
    public function строка_обратного_знака_внутри_документа_не_нарушение(): void
    {
        $uuid = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

        $this->entry(['type' => SettlementEntry::TYPE_COMMISSION_SALE, 'amount' => -5196, 'document_uuid' => $uuid]);
        $this->entry(['type' => SettlementEntry::TYPE_COMMISSION_SALE, 'amount' => 31.32, 'document_uuid' => $uuid]);
        $this->balance(-5164.68);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Но документ, у которого знак перевёрнут В ЦЕЛОМ, поймать обязаны.
     */
    #[Test]
    public function перевёрнутый_знак_документа_ловится(): void
    {
        $uuid = '8e1c3a52-6f4b-4b1e-9d0a-2c7f5a8b1d34';

        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => 120000, 'document_uuid' => $uuid]);
        $this->balance(120000);

        $this->artisan('settlements:verify')->assertExitCode(1);
    }

    /**
     * Сумма ленты до даты точки обязана сойтись со сверенной точкой. Расхождение
     * означает дырку в истории — обе стороны равенства приходят из регистра 1С.
     *
     * Долг прошлых периодов приезжает обычным движением 2025 года: лента приходит
     * целиком, отдельного начального сальдо в ней нет (v16.3.0).
     */
    #[Test]
    public function несошедшаяся_контрольная_точка_роняет_команду(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_ADJUSTMENT,
            'amount' => -50000,
            'date' => '2025-12-31',
        ]);
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -5000]);
        $this->balance(-55000);

        SettlementCheckpoint::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'as_of_date' => '2026-08-01',
            'amount' => -70000,
            'is_verified' => true,
        ]);

        $this->artisan('settlements:verify')->assertExitCode(1);
    }

    #[Test]
    public function сошедшаяся_контрольная_точка_не_мешает(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_ADJUSTMENT,
            'amount' => -50000,
            'date' => '2025-12-31',
        ]);
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -5000]);
        $this->balance(-55000);

        SettlementCheckpoint::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'as_of_date' => '2026-08-01',
            'amount' => -55000,
            'is_verified' => true,
        ]);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Ось точки — период движения регистра (16.12.0, топик №10).
     *
     * Боевой случай круга 13: платёж с датой документа 30.07 и периодом движения
     * 03.08 23:59:59. По прежней оси он попадал в точку на 01.08 и объявлял верную
     * точку расхождением; по периоду регистра — не попадает, и точка сходится.
     */
    #[Test]
    public function движение_с_периодом_после_точки_в_неё_не_входит(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -50000,
            'date' => '2026-07-01',
        ]);
        $this->entry([
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 548,
            'date' => '2026-07-30',
            'entry_date' => '2026-08-03 23:59:59',
        ]);
        $this->balance(-49452);

        $this->checkpoint(-50000);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Обратный случай: операция датирована после точки, а период движения — до неё.
     * В точку входит именно период.
     */
    #[Test]
    public function движение_с_периодом_до_точки_в_неё_входит(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -50000,
            'date' => '2026-08-05',
            'entry_date' => '2026-07-31 18:00:00',
        ]);
        $this->balance(-50000);

        $this->checkpoint(-50000);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Граница строгая и берётся в учётной зоне: 1С отбирает
     * `Период < НачалоДня(ДатаОтсечки)`, поэтому движение ровно в полночь даты точки
     * относится уже к следующему периоду.
     */
    #[Test]
    public function движение_ровно_в_полночь_даты_точки_в_неё_не_входит(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -50000,
            'date' => '2026-07-31',
        ]);
        $this->entry([
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 1000,
            'date' => '2026-07-31',
            'entry_date' => '2026-08-01 00:00:00',
        ]);
        $this->balance(-49000);

        $this->checkpoint(-50000);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Историческая часть ленты поля не получит, пока 1С не сделает массовую досылку:
     * строки без `entry_date` обязаны остаться на прежней оси, а не выпасть из точки.
     */
    #[Test]
    public function строка_без_периода_режется_по_прежней_оси(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -50000,
            'date' => '2026-07-15',
        ]);
        $this->balance(-50000);

        $this->checkpoint(-50000);

        $this->artisan('settlements:verify')
            ->expectsOutputToContain('Ось дат')
            ->assertExitCode(0);
    }

    /**
     * Контрагент с двумя партнёрами даёт две законные точки (топик №10, 28.09.2026).
     *
     * Партнёр входит в ключ точки у 1С, но в ось сверки сайта — контрагент ×
     * организация × валюта — не входит, поэтому лента на такого контрагента одна.
     * Поштучное сравнение объявляло пару расхождением дважды: боевой случай
     * Войдакова, точки −13 647,75 и −4 955,00 против ленты −18 602,75.
     */
    #[Test]
    public function две_точки_одного_контрагента_сравниваются_суммой(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -18602.75,
            'date' => '2026-07-15',
        ]);
        $this->balance(-18602.75);

        $this->checkpoint(-13647.75);
        $this->checkpoint(-4955.00);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Обратная проверка: суммирование не должно прятать настоящее расхождение.
     */
    #[Test]
    public function несошедшаяся_сумма_двух_точек_роняет_команду(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_SHIPMENT,
            'amount' => -18602.75,
            'date' => '2026-07-15',
        ]);
        $this->balance(-18602.75);

        $this->checkpoint(-13647.75);
        $this->checkpoint(-1000.00);

        $this->artisan('settlements:verify')->assertExitCode(1);
    }

    /**
     * Строка `opening_balance` в регистре — сама по себе нарушение с v16.3.0:
     * лента содержит историю целиком, и сальдо задваивает баланс. На проде это
     * стоило 13,7 млн ₽ и ста разошедшихся контрольных точек.
     */
    #[Test]
    public function строка_начального_сальдо_в_ленте_роняет_команду(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_OPENING_BALANCE,
            'amount' => -50000,
            'date' => '2026-01-01',
        ]);
        $this->balance(-50000);

        $this->artisan('settlements:verify')->assertExitCode(1);
    }

    /**
     * Копейки по тысяче контрагентов дают заметную сумму, поэтому порог
     * не «прощает» расхождение, а лишь выводит его отдельной строкой сводки.
     */
    #[Test]
    public function расхождение_в_пределах_порога_не_роняет_команду(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -55000.50]);
        $this->balance(-55000);

        $this->artisan('settlements:verify --threshold=1.00')
            ->expectsOutputToContain('В пределах порога')
            ->assertExitCode(0);
    }

    /**
     * Клиент без движений и с нулевым балансом — пустая строка, а не расхождение.
     */
    #[Test]
    public function пустой_контрагент_в_отчёт_не_попадает(): void
    {
        $this->balance(0);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    #[Test]
    public function команда_ничего_не_пишет_в_базу(): void
    {
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000]);
        $this->balance(-55000);

        $before = SettlementEntry::query()->sole()->updated_at;

        $this->artisan('settlements:verify');

        $this->assertEquals($before, SettlementEntry::query()->sole()->updated_at);
        $this->assertSame(1, SettlementEntry::query()->count());
    }
}
