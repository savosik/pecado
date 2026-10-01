<?php

namespace Tests\Feature\Notifications;

use App\Enums\PrintedDocumentType;
use App\Models\CrmEmail;
use App\Models\NotificationPreference;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Понедельничные поводы вокруг сверки.
 *
 * 1С выкладывает акты каждый день, и подписка на «опубликован документ» дала бы
 * клиенту ежедневное письмо, которое он перестанет замечать. Периодичность
 * выражена отдельным событием, а не настройкой «раз в неделю» — иначе
 * в матрицу вернулись бы расписания и условия.
 */
class WeeklyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = User::factory()->create();
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);
        $this->client = User::factory()->create([
            'personal_manager_id' => $profile->id,
            'email' => 'client@example.com',
        ]);

        config(['mail_stream.enabled' => true, 'mail_stream.autosend' => false]);
    }

    private function subscribe(string $key): void
    {
        NotificationPreference::query()->updateOrCreate(
            ['user_id' => $this->client->id, 'occasion_key' => $key],
            ['is_enabled' => true, 'destinations' => [['type' => 'login']]],
        );
    }

    private function debt(string $kind = 'shipment', int $dueInDays = -10): void
    {
        $company = \App\Models\Company::factory()->create(['user_id' => $this->client->id]);

        \App\Models\SettlementEntry::factory()->create([
            'user_id' => $this->client->id,
            'company_id' => $company->id,
            'nature' => 'plan',
            'document_kind' => $kind,
            'amount' => 5000,
            'settled_amount' => 0,
            'date' => today()->addDays($dueInDays),
        ]);
    }

    private function act(int $daysAgo = 1): PrintedDocument
    {
        $doc = PrintedDocument::factory()->create([
            'user_id' => $this->client->id,
            'type' => PrintedDocumentType::RECONCILIATION_ACT->value,
        ]);
        $doc->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $doc;
    }

    #[Test]
    public function сводка_за_неделю_собирает_акты_в_одно_письмо(): void
    {
        $this->subscribe('documents.reconciliation_weekly');
        $this->act(1);
        $this->act(3);
        $this->act(5);

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $letters = CrmEmail::query()->where('origin_event', 'documents.reconciliation_weekly')->get();

        // Три акта — одно письмо, а не три.
        $this->assertCount(1, $letters);
        $this->assertSame(3, $letters->first()->origin_data['documents_count']);
    }

    #[Test]
    public function без_новых_актов_письма_нет(): void
    {
        $this->subscribe('documents.reconciliation_weekly');
        $this->act(30);

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        // «За неделю ничего» — не новость, а повод перестать читать рассылку.
        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'documents.reconciliation_weekly')->count());
    }

    #[Test]
    public function неподписанному_клиенту_сводка_не_уходит(): void
    {
        $this->act(1);

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'documents.reconciliation_weekly')->count());
    }

    #[Test]
    public function сухой_прогон_писем_не_создаёт(): void
    {
        $this->subscribe('documents.reconciliation_weekly');
        $this->act(1);

        $this->artisan('mail:weekly-reconciliation', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, CrmEmail::query()->count());
    }

    #[Test]
    public function акты_при_долге_отдельное_событие(): void
    {
        // Подписка на сводку не включает вариант «только при долге»: это
        // разные строки в матрице, и выбирает клиент.
        $this->subscribe('documents.reconciliation_weekly');
        $this->act(1);

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->count());
    }

    #[Test]
    public function акты_при_долге_работают_без_подписки(): void
    {
        // Единственное клиентское уведомление, включённое умолчанием.
        // Оно ограничивает себя само: письмо уходит только должникам,
        // и новые должники подхватываются без ручной подписки.
        $this->act(1);
        $this->debt();

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $this->assertSame(
            1,
            CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->count(),
        );
    }

    #[Test]
    public function при_долге_акты_уходят_подписанному(): void
    {
        // Отбор «только при долге» — свойство события, а не массовая подписка:
        // клиент включает строку один раз, а система решает, в какой
        // понедельник письмо уместно.
        $this->subscribe('documents.reconciliation_when_debt');
        $this->act(1);
        $this->debt();

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $letter = CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->firstOrFail();

        $this->assertSame(1, $letter->origin_data['documents_count']);
        $this->assertGreaterThan(0, $letter->origin_data['overdue_amount']);
    }

    #[Test]
    public function без_долга_подписанному_ничего_не_уходит(): void
    {
        $this->subscribe('documents.reconciliation_when_debt');
        $this->act(1);

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->count());
    }

    #[Test]
    public function неоплаченная_реализация_внутри_отсрочки_долгом_не_считается(): void
    {
        // Случай Адалт Тойс (сентябрь 2026): отгрузка с отсрочкой 30 дней, срок
        // ещё не наступил — три понедельника подряд уходило письмо о задолженности.
        $this->subscribe('documents.reconciliation_when_debt');
        $this->act(1);
        $this->debt('shipment', dueInDays: 3);

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->count());
    }

    #[Test]
    public function в_сумму_письма_входит_только_просроченное(): void
    {
        $this->subscribe('documents.reconciliation_when_debt');
        $this->debt('shipment', dueInDays: -10);
        $this->debt('shipment', dueInDays: 5);

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $letter = CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->firstOrFail();

        $this->assertEquals(5000, $letter->origin_data['overdue_amount']);
        $this->assertSame(1, $letter->origin_data['positions_count']);
    }

    #[Test]
    public function кнопка_ведёт_в_оплаты_когда_раздел_открыт_клиенту(): void
    {
        config(['cabinet.finance_enabled' => true]);
        $this->subscribe('documents.reconciliation_when_debt');
        $this->debt();

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $letter = CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->firstOrFail();

        $this->assertStringContainsString('/cabinet/payments', (string) ($letter->body_html ?? $letter->body));
    }

    #[Test]
    public function план_по_заказу_долгом_не_считается(): void
    {
        // Заказ — это план, а не долг: долг создаёт отгрузка.
        $this->subscribe('documents.reconciliation_when_debt');
        $this->act(1);
        $this->debt('order');

        $this->artisan('mail:weekly-reconciliation')->assertSuccessful();

        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'documents.reconciliation_when_debt')->count());
    }
}
