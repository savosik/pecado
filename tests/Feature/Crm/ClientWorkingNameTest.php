<?php

namespace Tests\Feature\Crm;

use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Рабочее наименование партнёра в CRM.
 *
 * Менеджеры сличают отчёты сайта и 1С по наименованию карточки партнёра, а имя
 * в кабинете клиент правит как хочет. Поэтому сотруднику клиент показывается по
 * `erp_name`, а личное имя идёт рядом — и только когда действительно отличается.
 */
class ClientWorkingNameTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private PersonalManager $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
    }

    private function clientOf(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['personal_manager_id' => $this->card->id], $attrs));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(array $params = []): array
    {
        $response = $this->actingAs($this->manager)->get(route('crm.clients.index', $params));
        $response->assertOk();

        return $response->viewData('page')['props']['clients']['data'];
    }

    #[Test]
    public function list_shows_working_name_and_personal_name_separately(): void
    {
        $client = $this->clientOf([
            'name' => 'Как я себя назвал',
            'erp_name' => 'ООО «Ромашка» (Иванов)',
        ]);

        $row = collect($this->rows())->firstWhere('id', $client->id);

        $this->assertSame('ООО «Ромашка» (Иванов)', $row['name']);
        $this->assertSame('Как я себя назвал', $row['personal_name']);
    }

    #[Test]
    public function list_hides_personal_name_when_it_matches_working_name(): void
    {
        $client = $this->clientOf([
            'name' => 'ИП Петров',
            'erp_name' => 'ИП Петров',
        ]);

        $row = collect($this->rows())->firstWhere('id', $client->id);

        $this->assertSame('ИП Петров', $row['name']);
        $this->assertNull($row['personal_name']);
    }

    #[Test]
    public function list_falls_back_to_personal_name_without_erp_card(): void
    {
        // Зарегистрировался на сайте, карточки в 1С ещё нет — показывать нечего,
        // кроме того имени, что ввёл сам.
        $client = $this->clientOf([
            'name' => 'Сергей',
            'erp_name' => null,
        ]);

        $row = collect($this->rows())->firstWhere('id', $client->id);

        $this->assertSame('Сергей', $row['name']);
        $this->assertNull($row['personal_name']);
    }

    #[Test]
    public function search_finds_client_by_working_name(): void
    {
        $client = $this->clientOf([
            'name' => 'Сергей',
            'erp_name' => 'ООО «Василёк»',
        ]);
        $this->clientOf(['name' => 'Другой', 'erp_name' => 'ООО «Лютик»']);

        $ids = array_column($this->rows(['search' => 'Василёк']), 'id');

        $this->assertSame([$client->id], $ids);
    }

    #[Test]
    public function search_still_finds_client_by_personal_name(): void
    {
        $client = $this->clientOf([
            'name' => 'Иннокентий',
            'erp_name' => 'ООО «Василёк»',
        ]);

        $ids = array_column($this->rows(['search' => 'Иннокентий']), 'id');

        $this->assertSame([$client->id], $ids);
    }

    #[Test]
    public function sorting_by_name_uses_working_name(): void
    {
        // Порядок по личным именам был бы обратным — проверяем, что сортируется
        // именно та колонка, которую менеджер видит.
        $first = $this->clientOf(['name' => 'Яков', 'erp_name' => 'ААА Первый']);
        $second = $this->clientOf(['name' => 'Андрей', 'erp_name' => 'ЯЯЯ Последний']);

        $ids = array_column($this->rows(['sort_by' => 'name', 'sort_order' => 'asc']), 'id');

        $this->assertSame([$first->id, $second->id], $ids);
    }

    #[Test]
    public function client_card_shows_working_name_as_title(): void
    {
        $client = $this->clientOf([
            'name' => 'Как я себя назвал',
            'erp_name' => 'ООО «Ромашка» (Иванов)',
        ]);

        $response = $this->actingAs($this->manager)->get(route('crm.clients.show', $client->id));
        $response->assertOk();

        $payload = $response->viewData('page')['props']['client'];

        $this->assertSame('ООО «Ромашка» (Иванов)', $payload['name']);
        $this->assertSame('Как я себя назвал', $payload['personal_name']);
    }

    #[Test]
    public function cabinet_profile_update_does_not_touch_working_name(): void
    {
        $client = $this->clientOf([
            'name' => 'Старое имя',
            'erp_name' => 'ООО «Ромашка»',
            'email' => 'client@example.com',
        ]);

        $this->actingAs($client)
            ->put(route('cabinet.profile.update'), [
                'name' => 'Новое имя',
                'email' => 'client@example.com',
                'phone' => '+79990000000',
            ])
            ->assertRedirect();

        $client->refresh();

        $this->assertSame('Новое имя', $client->name);
        $this->assertSame('ООО «Ромашка»', $client->erp_name);
    }
}
