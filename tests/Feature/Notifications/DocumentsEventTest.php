<?php

namespace Tests\Feature\Notifications;

use App\Enums\ClientContactRole;
use App\Enums\PrintedDocumentType;
use App\Models\ClientContact;
use App\Models\Company;
use App\Models\NotificationDelivery;
use App\Models\NotificationRule;
use App\Models\NotificationRuleRecipient;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Notifications\Pulse\PulseNotification;
use App\Services\Notifications\Pulse\DocumentSignalDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Домен «Документы» — кейс заказчика про Серова.
 *
 * «Акты сверки по контрагенту приходят на одни емейлы, а реализации (сканы)
 * приходят на другие емейлы».
 */
class DocumentsEventTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Company $serov;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'notification_pulse.enabled' => true,
            'notification_pulse.mode' => 'live',
            'notification_pulse.live_events' => [],
            'notification_pulse.domains.documents.enabled' => true,
        ]);

        Notification::fake();

        $this->partner = User::factory()->create(['email' => 'client@serov.ru']);
        $this->serov = Company::factory()->create([
            'user_id' => $this->partner->id,
            'name' => 'ООО Серов',
            'tax_id' => '7712345678',
        ]);

        $this->contact('Бухгалтер Серова', ClientContactRole::ACCOUNTANT, 'buh@serov.ru');
        $this->contact('Логист Серова', ClientContactRole::LOGIST, 'logist@serov.ru');

        $this->buildSerovRules();
    }

    private function contact(string $name, ClientContactRole $role, string $email): ClientContact
    {
        return ClientContact::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => $this->serov->id,
            'full_name' => $name,
            'role' => $role,
            'email' => $email,
        ]);
    }

    private function buildSerovRules(): void
    {
        $acts = NotificationRule::factory()->forCompany($this->serov->id)->create([
            'name' => 'Акты сверки — бухгалтеру',
            'event_key' => 'documents.published',
            'conditions' => [
                'field' => 'document_type',
                'op' => '=',
                'value' => PrintedDocumentType::RECONCILIATION_ACT->value,
            ],
        ]);
        $acts->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE,
            'value' => ClientContactRole::ACCOUNTANT->value,
        ]);

        $shipments = NotificationRule::factory()->forCompany($this->serov->id)->create([
            'name' => 'Реализации — логисту',
            'event_key' => 'documents.published',
            'conditions' => [
                'field' => 'document_type',
                'op' => 'in',
                'value' => [
                    PrintedDocumentType::UPD->value,
                    PrintedDocumentType::WAYBILL->value,
                ],
            ],
        ]);
        $shipments->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_CONTACT_ROLE,
            'value' => ClientContactRole::LOGIST->value,
        ]);
    }

    private function document(PrintedDocumentType $type, array $overrides = []): PrintedDocument
    {
        return PrintedDocument::create(array_merge([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'type' => $type,
            'number' => 'Д-1',
            'date' => now()->toDateString(),
            'title' => $type->label(),
            'user_id' => $this->partner->id,
            'company_id' => $this->serov->id,
            'file_status' => PrintedDocument::FILE_STORED,
            'disk' => 'printed-documents',
            'path' => 'demo.pdf',
        ], $overrides));
    }

    /**
     * @return array<int, string>
     */
    private function sentTo(): array
    {
        $addresses = [];

        foreach (Notification::sentNotifications() as $byKey) {
            foreach ($byKey as $byType) {
                foreach ($byType[PulseNotification::class] ?? [] as $item) {
                    if ($item['notifiable'] instanceof AnonymousNotifiable) {
                        $addresses[] = $item['notifiable']->routes['mail'];
                    }
                }
            }
        }

        sort($addresses);

        return array_values(array_unique($addresses));
    }

    #[Test]
    #[TestDox('Акт сверки уходит бухгалтеру, а не логисту')]
    public function reconciliation_act_goes_to_accountant(): void
    {
        app(DocumentSignalDispatcher::class)->published(
            $this->document(PrintedDocumentType::RECONCILIATION_ACT)
        );

        $this->assertSame(['buh@serov.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('УПД уходит логисту, а не бухгалтеру')]
    public function upd_goes_to_logist(): void
    {
        app(DocumentSignalDispatcher::class)->published(
            $this->document(PrintedDocumentType::UPD)
        );

        $this->assertSame(['logist@serov.ru'], $this->sentTo());
    }

    #[Test]
    #[TestDox('Документ другого типа никому не уходит: правил на него нет')]
    public function unmatched_type_goes_nowhere(): void
    {
        app(DocumentSignalDispatcher::class)->published(
            $this->document(PrintedDocumentType::PRICE_LIST)
        );

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Документ без перенесённого файла сигнала не порождает')]
    public function pending_file_is_not_announced(): void
    {
        // Письмо со ссылкой на неперенесённый файл бесполезно: кабинет отдаст 404
        app(DocumentSignalDispatcher::class)->published(
            $this->document(PrintedDocumentType::RECONCILIATION_ACT, [
                'file_status' => PrintedDocument::FILE_PENDING,
            ])
        );

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Документ без контрагента ждёт доклейки, а не уходит мимо правил')]
    public function document_without_company_waits_for_relink(): void
    {
        app(DocumentSignalDispatcher::class)->published(
            $this->document(PrintedDocumentType::RECONCILIATION_ACT, ['company_id' => null])
        );

        Notification::assertNothingSent();
    }

    #[Test]
    #[TestDox('Историческая выгрузка не рассылается: срабатывает возрастной ценз')]
    public function historical_backfill_is_not_delivered(): void
    {
        config(['notification_pulse.limits.max_signal_age_minutes' => 60]);

        $document = $this->document(PrintedDocumentType::RECONCILIATION_ACT);
        // Так выглядит первичная выгрузка истории: документ создан давно
        PrintedDocument::whereKey($document->id)->update(['created_at' => now()->subDays(30)]);

        app(DocumentSignalDispatcher::class)->published($document->fresh());

        Notification::assertNothingSent();

        $delivery = NotificationDelivery::sole();
        $this->assertSame(NotificationDelivery::REASON_TOO_OLD, $delivery->skip_reason);
    }

    #[Test]
    #[TestDox('Домен выключен — сигналов нет вовсе')]
    public function disabled_domain_is_silent(): void
    {
        config(['notification_pulse.domains.documents.enabled' => false]);

        app(DocumentSignalDispatcher::class)->published(
            $this->document(PrintedDocumentType::RECONCILIATION_ACT)
        );

        Notification::assertNothingSent();
        $this->assertSame(0, NotificationDelivery::count());
    }

    #[Test]
    #[TestDox('Метка типа документа позволяет одно правило на все формы контрагента')]
    public function document_tag_catches_all_forms(): void
    {
        NotificationRule::query()->delete();

        $rule = NotificationRule::factory()->create([
            'name' => 'Все документы Серова — бухгалтеру',
            'event_key' => 'documents.*',
            'scope_type' => NotificationRule::SCOPE_GLOBAL,
            'conditions' => ['op' => 'has_tag', 'value' => 'инн:7712345678'],
        ]);
        $rule->recipients()->create([
            'kind' => NotificationRuleRecipient::KIND_EMAIL,
            'value' => 'all@serov.ru',
        ]);

        app(DocumentSignalDispatcher::class)->published(
            $this->document(PrintedDocumentType::PRICE_LIST)
        );

        $this->assertSame(['all@serov.ru'], $this->sentTo());
    }
}
