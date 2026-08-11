<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Organization;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Регистр взаиморасчётов: арифметика строки и скоупы (v16.0.0, карточка fin-03).
 *
 * Проверяется то, на чём прежняя модель денег и сломалась: знак, клампинг остатка
 * и отбор непогашенных строк. Ошибка в любом из трёх мест даёт клиенту неверный долг,
 * причём молча.
 */
class SettlementEntryTest extends TestCase
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
    private function entry(array $attributes = []): SettlementEntry
    {
        return SettlementEntry::factory()->create($attributes + [
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'organization_id' => $this->organization->id,
        ]);
    }

    /**
     * Плановая строка графика. Сумма положительная — это «сколько клиент должен
     * заплатить», а не движение баланса.
     */
    private function planEntry(float $amount, float $settled = 0, ?string $date = null): SettlementEntry
    {
        return $this->entry([
            'nature' => SettlementEntry::NATURE_PLAN,
            'type' => SettlementEntry::TYPE_PAYMENT_DUE,
            'amount' => $amount,
            'settled_amount' => $settled,
            'date' => $date ?? Carbon::today()->addDays(14)->toDateString(),
        ]);
    }

    /**
     * Числовой пример из контракта: сальдо −50 000, реализация −120 000,
     * оплата +100 000, возврат товара +20 000, возврат денег −5 000 → долг 55 000 ₽.
     *
     * Это ровно формула акта сверки заказчика, и она обязана получаться
     * одним SUM без единого CASE.
     */
    #[Test]
    public function баланс_считается_суммой_одной_колонки(): void
    {
        $movements = [
            [SettlementEntry::TYPE_OPENING_BALANCE, -50000],
            [SettlementEntry::TYPE_SHIPMENT, -120000],
            [SettlementEntry::TYPE_PAYMENT_IN, 100000],
            [SettlementEntry::TYPE_GOODS_RETURN, 20000],
            [SettlementEntry::TYPE_PAYMENT_OUT, -5000],
        ];

        foreach ($movements as [$type, $amount]) {
            $this->entry(['type' => $type, 'amount' => $amount]);
        }

        // Плановая строка в баланс попасть не должна: деньги ещё не пришли.
        $this->planEntry(70000);

        $balance = SettlementEntry::query()->facts()->sum('amount');

        $this->assertEqualsWithDelta(-55000.0, (float) $balance, 0.01);
    }

    /**
     * Дебет и кредит акта сверки — generated-колонки. Считаются движком БД,
     * а не приложением: иначе каждый SQL-запрос ИИ-агента превращался бы в CASE.
     */
    #[Test]
    public function дебет_и_кредит_выводятся_из_знака_суммы(): void
    {
        $shipment = $this->entry(['type' => SettlementEntry::TYPE_SHIPMENT, 'amount' => -120000]);
        $payment = $this->entry(['type' => SettlementEntry::TYPE_PAYMENT_IN, 'amount' => 100000]);

        $this->assertEqualsWithDelta(120000.0, (float) $shipment->fresh()->debit, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $shipment->fresh()->credit, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $payment->fresh()->debit, 0.01);
        $this->assertEqualsWithDelta(100000.0, (float) $payment->fresh()->credit, 0.01);
    }

    #[Test]
    public function остаток_плановой_строки_учитывает_погашенную_часть(): void
    {
        $line = $this->planEntry(100000, 40000);

        $this->assertEqualsWithDelta(60000.0, $line->unsettled_amount, 0.01);
    }

    /**
     * Переплата по строке легитимна — так 1С отражает досрочный платёж.
     * Отрицательного остатка быть не должно: он вычитался бы из общего долга
     * и занижал его.
     */
    #[Test]
    public function переплата_не_создаёт_отрицательного_остатка(): void
    {
        $line = $this->planEntry(100000, 150000);

        $this->assertSame(0.0, $line->unsettled_amount);
    }

    #[Test]
    public function у_фактической_строки_остатка_нет(): void
    {
        $fact = $this->entry(['amount' => -120000, 'settled_amount' => 0]);

        $this->assertSame(0.0, $fact->unsettled_amount);
    }

    /**
     * Скоуп непогашенных подставляет EPSILON прямо в SQL. Если сделать это
     * биндингом, SQLite сравнит число со строкой '0.01' — текст всегда больше
     * числа, и выборка окажется пустой. На этой грабле уже стояли
     * в ShipmentPaymentSchedule.
     */
    #[Test]
    public function непогашенные_строки_находятся_и_на_sqlite(): void
    {
        $this->planEntry(100000, 40000);
        $this->planEntry(50000, 50000);
        $this->entry(['amount' => -120000]);

        $outstanding = SettlementEntry::query()->outstanding()->get();

        $this->assertCount(1, $outstanding);
        $this->assertEqualsWithDelta(60000.0, $outstanding->first()->unsettled_amount, 0.01);
    }

    #[Test]
    public function просрочка_это_непогашенный_план_с_прошедшей_датой(): void
    {
        $overdue = $this->planEntry(100000, 0, Carbon::today()->subDays(10)->toDateString());
        $this->planEntry(80000, 0, Carbon::today()->addDays(5)->toDateString());
        // Просроченная по дате, но закрытая деньгами — не просрочка.
        $this->planEntry(30000, 30000, Carbon::today()->subDays(20)->toDateString());

        $rows = SettlementEntry::query()->overdue()->get();

        $this->assertCount(1, $rows);
        $this->assertSame($overdue->id, $rows->first()->id);
        $this->assertTrue($overdue->fresh()->is_overdue);
    }

    #[Test]
    public function фактическая_строка_никогда_не_просрочена(): void
    {
        $fact = $this->entry(['amount' => -120000, 'date' => Carbon::today()->subYear()->toDateString()]);

        $this->assertFalse($fact->is_overdue);
    }

    /**
     * Срез на дату — основа акта сверки: сальдо на начало периода и на конец
     * считаются одним и тем же скоупом с разными датами.
     */
    #[Test]
    public function срез_на_дату_отсекает_будущие_операции(): void
    {
        $this->entry(['amount' => -100000, 'date' => '2026-06-30']);
        $this->entry(['amount' => 40000, 'date' => '2026-07-02']);

        $balance = SettlementEntry::query()->facts()->upTo(Carbon::parse('2026-07-01'))->sum('amount');

        $this->assertEqualsWithDelta(-100000.0, (float) $balance, 0.01);
    }

    /**
     * Ось акта сверки — контрагент × организация × валюта. Соглашение в неё
     * не входит: 1С берёт его из документа-регистратора, и при изменении задним
     * числом группировка исторических движений разъезжается.
     */
    #[Test]
    public function ось_сверки_отбирает_по_контрагенту_организации_и_валюте(): void
    {
        $other = Organization::factory()->create();

        $this->entry(['amount' => -100000]);
        $this->entry(['amount' => -70000, 'organization_id' => $other->id]);
        $this->entry(['amount' => -500, 'currency_code' => 'EUR']);

        $balance = SettlementEntry::query()
            ->facts()
            ->forReconciliation($this->company->id, $this->organization->id)
            ->sum('amount');

        $this->assertEqualsWithDelta(-100000.0, (float) $balance, 0.01);
    }

    #[Test]
    public function подписи_строки_переведены_на_русский(): void
    {
        $entry = $this->entry([
            'type' => SettlementEntry::TYPE_PAYMENT_IN,
            'amount' => 100000,
            'document_kind' => 'payment',
            'document_number' => '29УТ-002488',
        ]);

        $this->assertSame('Поступление средств', $entry->type_label);
        $this->assertSame('В пользу клиента', $entry->direction_label);
        $this->assertSame('Платёжный документ 29УТ-002488', $entry->document_label);
    }

    /**
     * Незнакомый вид документа не должен превращаться в прочерк: перечень
     * на стороне 1С открытый, и сырой код полезнее пустоты.
     */
    #[Test]
    public function незнакомый_вид_документа_показывается_как_есть(): void
    {
        $entry = $this->entry(['document_kind' => 'ещё_не_описанный_вид', 'document_number' => 'X-1']);

        $this->assertSame('ещё_не_описанный_вид X-1', $entry->document_label);
    }

    /**
     * Движение живёт без документа на сайте: отчёт комиссионера сюда не приезжает
     * вовсе, а реализация может опоздать. Строка обязана читаться по своим
     * продублированным реквизитам.
     */
    #[Test]
    public function движение_без_документа_на_сайте_сохраняется_и_читается(): void
    {
        $entry = $this->entry([
            'document_type' => null,
            'document_id' => null,
            'document_kind' => 'commission_report',
            'document_number' => 'КМ-000123',
        ]);

        $this->assertNull($entry->document);
        $this->assertSame('Отчёт комиссионера КМ-000123', $entry->document_label);
    }
}
