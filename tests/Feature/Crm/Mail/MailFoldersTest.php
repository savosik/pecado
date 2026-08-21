<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use App\Models\PersonalManager;
use App\Models\User;
use App\Services\Crm\Mail\MailStream;
use App\Support\Notifications\Occasion;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Папки: не новая сущность, а другой показ того же списка.
 *
 * Статус письма и есть папка. «Черновики» открываются первыми, потому что это
 * рабочая папка — та, в которой нажимают самолётик.
 */
class MailFoldersTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();
        Mail::fake();

        $this->manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $this->manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $profile->id]);

        config([
            'mail_stream.enabled' => true,
            'notifications.mail.features.crm_outbound' => true,
        ]);
    }

    private function props(array $query = []): array
    {
        return $this->actingAs($this->manager)
            ->get(route('crm.emails.index', $query))
            ->viewData('page')['props'];
    }

    private function systemLetter(string $number = '1023'): CrmEmail
    {
        return app(MailStream::class)->capture(new Occasion(
            key: 'documents.published',
            clientUserId: $this->client->id,
            data: ['document_type' => 'reconciliation_act', 'document_number' => $number, 'document_title' => 'Акт сверки'],
            view: ['title' => 'Акт сверки', 'body' => 'Документ выложен'],
        ));
    }

    #[Test]
    public function drafts_open_first(): void
    {
        $props = $this->props();

        $this->assertSame('drafts', $props['filters']['folder']);
    }

    #[Test]
    public function folders_carry_counters(): void
    {
        CrmEmail::factory()->by($this->manager)->on($this->client)->create();
        CrmEmail::factory()->by($this->manager)->on($this->client)->sent()->create();
        $this->systemLetter();

        $counts = collect($this->props()['folders'])->pluck('count', 'value')->all();

        $this->assertSame(1, $counts['drafts']);
        $this->assertSame(1, $counts['sent']);
        $this->assertSame(1, $counts['unmatched']);
        $this->assertSame(0, $counts['failed']);
    }

    #[Test]
    public function letter_shows_who_composed_it(): void
    {
        $this->systemLetter();

        $letter = $this->props(['folder' => 'unmatched'])['emails']['data'][0];

        $this->assertSame('system', $letter['origin']);
        $this->assertSame('Система', $letter['origin_label']);
        $this->assertNotEmpty($letter['tags']);
    }

    #[Test]
    public function search_works_inside_the_current_folder(): void
    {
        CrmEmail::factory()->by($this->manager)->on($this->client)->create(['subject' => 'Коммерческое предложение']);
        CrmEmail::factory()->by($this->manager)->on($this->client)->create(['subject' => 'Реквизиты']);

        $found = $this->props(['search' => 'Реквизиты'])['emails']['data'];

        $this->assertCount(1, $found);
        $this->assertSame('Реквизиты', $found[0]['subject']);
    }

    #[Test]
    public function drafts_are_sent_in_bulk(): void
    {
        $first = CrmEmail::factory()->by($this->manager)->on($this->client)->create();
        $second = CrmEmail::factory()->by($this->manager)->on($this->client)->create();

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.bulk-send'), ['ids' => [$first->id, $second->id]])
            ->assertOk()
            ->assertJsonPath('sent', 2);

        $this->assertSame(EmailStatus::SENT, $first->refresh()->status);
        $this->assertSame(EmailStatus::SENT, $second->refresh()->status);
    }

    #[Test]
    public function drafts_are_deleted_in_bulk(): void
    {
        $first = CrmEmail::factory()->by($this->manager)->on($this->client)->create();
        $sent = CrmEmail::factory()->by($this->manager)->on($this->client)->sent()->create();

        $this->actingAs($this->manager)
            ->postJson(route('crm.emails.bulk-delete'), ['ids' => [$first->id, $sent->id]])
            ->assertOk()
            ->assertJsonPath('deleted', 1);

        // Отправленное остаётся в журнале навсегда — это единственный след того,
        // что клиенту написали.
        $this->assertNotNull($sent->fresh());
        $this->assertNull($first->fresh());
    }

    #[Test]
    public function unmatched_folder_explains_what_is_not_configured(): void
    {
        $this->systemLetter('1023');
        $this->systemLetter('1024');

        $summary = $this->props(['folder' => 'unmatched'])['unmatchedSummary'];

        $this->assertSame(2, $summary['rows'][0]['total']);
        $this->assertSame('documents.published', $summary['rows'][0]['event']);
        $this->assertSame(14, $summary['retention_days']);
        // Кнопка «настроить» ведёт в форму с уже набранным условием: иначе
        // менеджер, увидевший сводку, переписывал бы метку руками.
        $this->assertSame('документы', $summary['rows'][0]['tag']);
    }

    #[Test]
    public function rule_form_opens_with_the_condition_from_the_summary(): void
    {
        $props = $this->actingAs($this->manager)
            ->get(route('crm.emails.rules.index', ['tag' => 'документы']))
            ->viewData('page')['props'];

        $this->assertSame('документы', $props['prefillTag']);
    }

    #[Test]
    public function unmatched_letters_are_pruned_after_retention(): void
    {
        // Данные не копятся: иначе повторилась бы история, когда журналы
        // мониторинга съели почти всю боевую базу.
        $old = $this->systemLetter('1023');
        $old->forceFill(['created_at' => now()->subDays(20)])->save();
        $fresh = $this->systemLetter('1024');

        $this->artisan('mail:prune-unmatched')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNotNull($fresh->fresh());
    }
}
