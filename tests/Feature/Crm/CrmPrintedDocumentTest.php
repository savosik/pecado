<?php

namespace Tests\Feature\Crm;

use App\Models\Company;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Журнал печатных форм внутри CRM (v16.1.0).
 *
 * Отдельного права у раздела нет — доступ решает скоуп партнёров, как у журналов
 * заказов и реализаций. Поэтому изоляция проверяется на каждом входе: скачивание
 * по прямой ссылке это ещё один способ добраться до чужих документов, зная ID.
 */
class CrmPrintedDocumentTest extends TestCase
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

        Storage::fake('printed-documents');
        config(['documents.crm_enabled' => true, 'documents.disk' => 'printed-documents']);

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $card = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $card->id]);
    }

    private function documentFor(?User $client, array $attributes = []): PrintedDocument
    {
        $document = PrintedDocument::factory()->create(array_merge([
            'user_id' => $client?->id,
            'company_id' => $client ? Company::factory()->create(['user_id' => $client->id])->id : null,
        ], $attributes));

        if ($document->path) {
            Storage::disk('printed-documents')->put($document->path, '%PDF-1.7 тест');
        }

        return $document;
    }

    private function foreignClient(): User
    {
        return User::factory()->create([
            'personal_manager_id' => PersonalManager::factory()->create()->id,
        ]);
    }

    #[Test]
    public function manager_sees_only_documents_of_his_partners(): void
    {
        $own = $this->documentFor($this->client);
        $this->documentFor($this->foreignClient());

        $this->actingAs($this->manager)
            ->get('/crm/printed-documents')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Crm/Pages/Documents/PrintedDocuments')
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $own->id));
    }

    #[Test]
    public function foreign_document_cannot_be_downloaded(): void
    {
        $foreign = $this->documentFor($this->foreignClient());

        $this->actingAs($this->manager)
            ->get("/crm/printed-documents/{$foreign->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function own_document_downloads(): void
    {
        $document = $this->documentFor($this->client);

        $this->actingAs($this->manager)
            ->get("/crm/printed-documents/{$document->id}/download")
            ->assertOk();
    }

    #[Test]
    public function unmatched_document_is_visible_only_to_department_wide_role(): void
    {
        // Документ, который обмен ещё не сопоставил с партнёром. Рядовому
        // менеджеру он «ничей», а РОП должен его видеть — иначе непривязанные
        // документы не разберёт никто.
        $orphan = $this->documentFor(null);

        $this->actingAs($this->manager)
            ->get('/crm/printed-documents')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('documents.data', 0));

        $this->actingAs($this->manager)
            ->get("/crm/printed-documents/{$orphan->id}/download")
            ->assertNotFound();

        $head = User::factory()->create();
        $head->assignRole('sales-head');

        $this->actingAs($head)
            ->get('/crm/printed-documents')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('documents.data', 1));
    }

    #[Test]
    public function manager_sees_problem_files_unlike_the_client(): void
    {
        // Диагностика обмена: менеджеру нужно видеть, что файл не доехал —
        // клиенту такие документы не показываются вовсе.
        $missing = $this->documentFor($this->client, [
            'file_status' => PrintedDocument::FILE_MISSING,
            'path' => null,
        ]);

        $this->actingAs($this->manager)
            ->get('/crm/printed-documents?file_statuses[]=missing')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $missing->id)
                // Кнопки скачивания у такой строки нет: качать нечего.
                ->where('documents.data.0.download_url', null));
    }

    #[Test]
    public function export_is_scoped_the_same_way(): void
    {
        $this->documentFor($this->client);
        $this->documentFor($this->foreignClient());

        $this->actingAs($this->manager)
            ->get('/crm/printed-documents/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Test]
    public function section_is_closed_by_its_own_flag(): void
    {
        config(['documents.crm_enabled' => false]);
        $document = $this->documentFor($this->client);

        $this->actingAs($this->manager)->get('/crm/printed-documents')->assertNotFound();
        $this->actingAs($this->manager)->get('/crm/printed-documents/export')->assertNotFound();
        $this->actingAs($this->manager)
            ->get("/crm/printed-documents/{$document->id}/download")
            ->assertNotFound();
    }
}
