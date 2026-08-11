<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Agreement;
use App\Models\Company;
use App\Models\SettlementEntry;
use App\Models\User;
use App\Services\Erp\Handlers\HandleAgreementDeleted;
use App\Services\Erp\Handlers\HandleAgreementUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Обработчики соглашений с клиентами (v16.0.0, карточка fin-04).
 */
class HandleAgreementTest extends TestCase
{
    use RefreshDatabase;

    private const AGREEMENT_UUID = '5c8a2f4d-7e1b-4903-a6c5-8f2d4b7e1a39';

    private const CONTRACTOR_UUID = 'b4d8e2f1-6c5a-4917-8e3b-2f9a7d4c1508';

    private const PARTNER_UUID = '7c9e6b21-4a3d-4e8f-b512-9d7c3e1a6f04';

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'event' => 'agreement.updated',
            'message_id' => 'msg-'.uniqid(),
            'uuid' => self::AGREEMENT_UUID,
            'partner_uuid' => self::PARTNER_UUID,
            'contractor_uuid' => self::CONTRACTOR_UUID,
            'number' => 'СГ-0042',
            'name' => 'Соглашение об условиях продаж №СГ-0042',
        ];
    }

    /**
     * `updated` на неизвестное соглашение создаёт его. Потерянное событие создания
     * иначе означало бы навсегда отсутствующее соглашение — payload полный,
     * восстановить его нечем.
     */
    #[Test]
    public function обновление_неизвестного_соглашения_создаёт_его(): void
    {
        app(HandleAgreementUpdated::class)->handle($this->payload());

        $agreement = Agreement::query()->sole();

        $this->assertSame(self::AGREEMENT_UUID, $agreement->uuid);
        $this->assertSame(Agreement::STATUS_ACTIVE, $agreement->status);
    }

    /**
     * Отсутствие поля означает «1С не прислала», а не «сбросить». Сообщение
     * без статуса не должно открывать обратно закрытое соглашение.
     */
    #[Test]
    public function обновление_без_статуса_не_открывает_закрытое(): void
    {
        Agreement::factory()->closed()->create(['uuid' => self::AGREEMENT_UUID]);

        app(HandleAgreementUpdated::class)->handle($this->payload());

        $this->assertSame(Agreement::STATUS_CLOSED, Agreement::query()->sole()->status);
    }

    #[Test]
    public function связи_доклеиваются_когда_контрагент_уже_на_сайте(): void
    {
        $user = User::factory()->create(['erp_id' => self::PARTNER_UUID]);
        $company = Company::factory()->create(['user_id' => $user->id, 'erp_id' => self::CONTRACTOR_UUID]);

        app(HandleAgreementUpdated::class)->handle($this->payload());

        $agreement = Agreement::query()->sole();

        $this->assertSame($company->id, $agreement->company_id);
        $this->assertSame($user->id, $agreement->user_id);
    }

    #[Test]
    public function повторная_доставка_не_создаёт_второе_соглашение(): void
    {
        app(HandleAgreementUpdated::class)->handle($this->payload());
        app(HandleAgreementUpdated::class)->handle($this->payload(['name' => 'Новое наименование']));

        $this->assertSame(1, Agreement::query()->count());
        $this->assertSame('Новое наименование', Agreement::query()->sole()->name);
    }

    /**
     * Пометка удаления соглашения задолженность не отменяет: движения регистра
     * остаются, `agreement_id` в них сохраняется, история продолжает читаться.
     * Каскад здесь был бы прямой потерей денег.
     */
    #[Test]
    public function удаление_соглашения_не_трогает_движения(): void
    {
        $agreement = Agreement::factory()->create(['uuid' => self::AGREEMENT_UUID]);
        $entry = SettlementEntry::factory()->create(['agreement_id' => $agreement->id]);

        app(HandleAgreementDeleted::class)->handle([
            'event' => 'agreement.deleted',
            'message_id' => 'msg-del',
            'uuid' => self::AGREEMENT_UUID,
        ]);

        $this->assertSoftDeleted($agreement);
        $this->assertSame(Agreement::STATUS_CLOSED, Agreement::withTrashed()->sole()->status);
        $this->assertSame($agreement->id, $entry->fresh()->agreement_id);
    }

    /**
     * Снятие пометки удаления в 1С — обычная операция, а не исключение.
     */
    #[Test]
    public function повторное_проведение_возвращает_удалённое_соглашение(): void
    {
        Agreement::factory()->create(['uuid' => self::AGREEMENT_UUID])->delete();

        app(HandleAgreementUpdated::class)->handle($this->payload());

        $this->assertSame(1, Agreement::query()->count());
        $this->assertNull(Agreement::query()->sole()->deleted_at);
    }
}
