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
     * «Сальдо на 01.01 + движения первого полугодия» обязано сойтись со сверенной
     * точкой на 01.07. Расхождение означает, что история H1 недостоверна.
     */
    #[Test]
    public function несошедшаяся_контрольная_точка_роняет_команду(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_OPENING_BALANCE,
            'amount' => -50000,
            'date' => '2026-01-01',
        ]);
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -5000]);
        $this->balance(-55000);

        SettlementCheckpoint::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'as_of_date' => '2026-07-01',
            'amount' => -70000,
            'is_verified' => true,
        ]);

        $this->artisan('settlements:verify')->assertExitCode(1);
    }

    #[Test]
    public function сошедшаяся_контрольная_точка_не_мешает(): void
    {
        $this->entry([
            'type' => SettlementEntry::TYPE_OPENING_BALANCE,
            'amount' => -50000,
            'date' => '2026-01-01',
        ]);
        $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -5000]);
        $this->balance(-55000);

        SettlementCheckpoint::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
            'currency_code' => 'RUB',
            'as_of_date' => '2026-07-01',
            'amount' => -55000,
            'is_verified' => true,
        ]);

        $this->artisan('settlements:verify')->assertExitCode(0);
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
