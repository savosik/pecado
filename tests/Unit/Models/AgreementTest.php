<?php

namespace Tests\Unit\Models;

use App\Models\Agreement;
use App\Models\SettlementCheckpoint;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Соглашения и контрольные точки сальдо (v16.0.0, карточка fin-03).
 */
class AgreementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function действующие_соглашения_отбираются_скоупом(): void
    {
        $active = Agreement::factory()->create();
        Agreement::factory()->closed()->create();

        $rows = Agreement::query()->active()->get();

        $this->assertCount(1, $rows);
        $this->assertSame($active->id, $rows->first()->id);
    }

    #[Test]
    public function порядок_расчётов_показывается_по_русски(): void
    {
        $agreement = Agreement::factory()->create(['settlement_procedure' => 'settlement_documents']);

        $this->assertSame('По расчётным документам', $agreement->settlement_procedure_label);
    }

    /**
     * Порядок расчётов не заполнен у 167 соглашений в боевой базе — это штатное
     * состояние, а не ошибка данных.
     */
    #[Test]
    public function незаполненный_порядок_расчётов_допустим(): void
    {
        $agreement = Agreement::factory()->create(['settlement_procedure' => null]);

        $this->assertSame('Не задан', $agreement->settlement_procedure_label);
    }

    /**
     * Перечень на стороне 1С может пополниться. Прятать новое значение за прочерком
     * вреднее, чем показать сырой код: так расхождение хотя бы видно.
     */
    #[Test]
    public function незнакомый_порядок_расчётов_показывается_как_есть(): void
    {
        $agreement = Agreement::factory()->create(['settlement_procedure' => 'по_новому_регламенту']);

        $this->assertSame('по_новому_регламенту', $agreement->settlement_procedure_label);
    }

    #[Test]
    public function отображаемое_имя_откатывается_к_номеру_и_uuid(): void
    {
        $named = Agreement::factory()->create(['name' => 'Соглашение №СГ-0042']);
        $numbered = Agreement::factory()->create(['name' => null, 'number' => 'СГ-0043']);
        $bare = Agreement::factory()->create(['name' => null, 'number' => null]);

        $this->assertSame('Соглашение №СГ-0042', $named->display_name);
        $this->assertSame('Соглашение №СГ-0043', $numbered->display_name);
        $this->assertSame($bare->uuid, $bare->display_name);
    }

    /**
     * Соглашение может приехать раньше контрагента: порядок доставки между
     * очередями не гарантирован. Терять его из-за этого нельзя — сырые UUID
     * рядом позволяют доклеить связи позже.
     */
    #[Test]
    public function соглашение_сохраняется_без_сопоставленного_контрагента(): void
    {
        $agreement = Agreement::factory()->unmatched()->create(['contractor_uuid' => 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508']);

        $this->assertNull($agreement->company_id);
        $this->assertSame('b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508', $agreement->contractor_uuid);
    }

    #[Test]
    public function сверенные_контрольные_точки_отделены_от_технических(): void
    {
        $verified = SettlementCheckpoint::factory()->create();
        SettlementCheckpoint::factory()->openingBalance()->create();

        $rows = SettlementCheckpoint::query()->verified()->get();

        $this->assertCount(1, $rows);
        $this->assertSame($verified->id, $rows->first()->id);
        $this->assertTrue(SettlementCheckpoint::query()->asOf(Carbon::parse('2026-01-01'))->exists());
    }

    /**
     * Повторная доставка не должна задваивать контрольную сумму: задвоенная точка
     * хуже отсутствующей — сверка покажет расхождение там, где его нет.
     *
     * Организация здесь пустой строкой, а не NULL, именно поэтому: уникальный
     * индекс MySQL считает NULL-ы различными.
     */
    #[Test]
    public function контрольная_точка_не_задваивается_без_организации(): void
    {
        $attributes = [
            'contractor_uuid' => 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508',
            'organization_id' => null,
            'organization_uuid' => '',
            'as_of_date' => '2026-07-01',
            'currency_code' => 'RUB',
            'amount' => -55000,
        ];

        SettlementCheckpoint::factory()->create($attributes);

        $this->expectException(QueryException::class);

        SettlementCheckpoint::factory()->create($attributes);
    }
}
