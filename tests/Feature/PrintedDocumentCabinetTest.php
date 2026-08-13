<?php

namespace Tests\Feature;

use App\Enums\PrintedDocumentType;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\Organization;
use App\Models\PrintedDocument;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Раздел «Документы» в личном кабинете (v16.1.0).
 *
 * Ключевое требование: клиент видит документы ВСЕХ своих контрагентов, а не
 * только те, у которых есть заказ или отгрузка на сайте — договор и акт сверки
 * основания не имеют вовсе.
 */
class PrintedDocumentCabinetTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('printed-documents');

        config([
            'documents.enabled' => true,
            'documents.disk' => 'printed-documents',
            'search-cabinet.export' => true,
        ]);

        $this->user = User::factory()->create([
            'status' => UserStatus::ACTIVE,
            'must_change_password' => false,
        ]);

        $this->company = Company::factory()->create(['user_id' => $this->user->id]);
    }

    private function document(array $attributes = []): PrintedDocument
    {
        $document = PrintedDocument::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ], $attributes));

        if ($document->path) {
            Storage::disk('printed-documents')->put($document->path, '%PDF-1.7 тест');
        }

        return $document;
    }

    #[Test]
    public function section_is_hidden_while_feature_is_off(): void
    {
        config(['documents.enabled' => false]);
        $document = $this->document();

        $this->actingAs($this->user)->get('/cabinet/documents')->assertNotFound();
        $this->actingAs($this->user)->get('/cabinet/documents/export?format=csv')->assertNotFound();
        $this->actingAs($this->user)->get("/cabinet/documents/{$document->id}/download")->assertNotFound();
    }

    #[Test]
    public function client_sees_documents_of_all_his_contractors_without_any_base_document(): void
    {
        $second = Company::factory()->create(['user_id' => $this->user->id]);

        // Договор и акт сверки не привязаны ни к заказу, ни к отгрузке.
        $contract = $this->document([
            'company_id' => $second->id,
            'order_id' => null,
            'shipment_id' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/cabinet/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('User/Cabinet/Documents/Index')
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $contract->id));
    }

    #[Test]
    public function document_without_company_but_with_own_user_is_visible(): void
    {
        // Контрагент ещё не приехал из 1С — до доклейки документ виден
        // по денормализованному партнёру, иначе клиент не нашёл бы свой счёт.
        $document = $this->document(['company_id' => null]);

        $this->actingAs($this->user)
            ->get('/cabinet/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1)
                ->where('documents.data.0.id', $document->id));
    }

    #[Test]
    public function foreign_document_is_neither_listed_nor_downloadable(): void
    {
        $stranger = User::factory()->create();
        $foreign = PrintedDocument::factory()->create([
            'user_id' => $stranger->id,
            'company_id' => Company::factory()->create(['user_id' => $stranger->id])->id,
        ]);

        $this->actingAs($this->user)
            ->get('/cabinet/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 0));

        // 404, а не 403: 403 подтвердил бы, что чужой документ существует.
        $this->actingAs($this->user)
            ->get("/cabinet/documents/{$foreign->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function documents_without_stored_file_are_not_shown(): void
    {
        $this->document(['file_status' => PrintedDocument::FILE_PENDING]);
        $this->document(['file_status' => PrintedDocument::FILE_MISSING]);
        $stored = $this->document();

        $this->actingAs($this->user)
            ->get('/cabinet/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1)
                ->where('documents.data.0.id', $stored->id));
    }

    #[Test]
    public function pending_document_cannot_be_downloaded(): void
    {
        $pending = $this->document(['file_status' => PrintedDocument::FILE_PENDING, 'path' => null]);

        $this->actingAs($this->user)
            ->get("/cabinet/documents/{$pending->id}/download")
            ->assertNotFound();
    }

    #[Test]
    public function download_returns_file_with_readable_name(): void
    {
        $document = $this->document([
            'number' => '29УТ-002488',
            'date' => '2026-08-12',
        ]);

        $response = $this->actingAs($this->user)
            ->get("/cabinet/documents/{$document->id}/download")
            ->assertOk();

        $disposition = $response->headers->get('content-disposition');

        $this->assertStringContainsString('attachment', $disposition);
        // Имя собирается из вида и номера, а не берётся из имени файла 1С:
        // иначе в папке загрузок клиента документы неразличимы.
        $this->assertStringContainsString('29', rawurldecode($disposition));
    }

    #[Test]
    public function type_filter_and_counts_work(): void
    {
        $this->document(['type' => PrintedDocumentType::TAX_INVOICE]);
        $this->document(['type' => PrintedDocumentType::TAX_INVOICE]);
        $act = $this->document(['type' => PrintedDocumentType::RECONCILIATION_ACT]);

        $this->actingAs($this->user)
            ->get('/cabinet/documents?type[]=reconciliation_act')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.id', $act->id)
                // Счётчики считаются без фильтра по виду — иначе чипы обнулялись
                // бы, стоило выбрать один из них.
                ->where('typeCounts.tax_invoice', 2)
                ->where('typeCounts.reconciliation_act', 1));
    }

    #[Test]
    public function date_and_company_filters_narrow_the_list(): void
    {
        $other = Company::factory()->create(['user_id' => $this->user->id]);

        $this->document(['date' => '2026-01-15']);
        $target = $this->document(['date' => '2026-08-12', 'company_id' => $other->id]);

        $this->actingAs($this->user)
            ->get('/cabinet/documents?date_from=2026-08-01&company_id[]='.$other->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1)
                ->where('documents.data.0.id', $target->id));
    }

    #[Test]
    public function foreign_company_id_in_filter_yields_nothing(): void
    {
        $stranger = Company::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->document();

        $this->actingAs($this->user)
            ->get('/cabinet/documents?company_id[]='.$stranger->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 0));
    }

    #[Test]
    public function deep_link_from_shipment_filters_by_base_document(): void
    {
        $shipment = Shipment::factory()->create(['user_id' => $this->user->id]);
        $linked = $this->document(['shipment_id' => $shipment->id]);
        $this->document();

        $this->actingAs($this->user)
            ->get('/cabinet/documents?shipment_id='.$shipment->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1)
                ->where('documents.data.0.id', $linked->id));
    }

    #[Test]
    public function search_finds_document_by_normalised_number(): void
    {
        $target = $this->document(['number' => '29УТ-002488']);
        $this->document(['number' => '30УТ-000001']);

        // Клиент копирует номер из письма и набирает его без дефиса.
        $this->actingAs($this->user)
            ->get('/cabinet/documents?search=29УТ002488')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('documents.data', 1)
                ->where('documents.data.0.id', $target->id));
    }

    #[Test]
    public function export_respects_flag_and_format(): void
    {
        $this->document();

        $this->actingAs($this->user)->get('/cabinet/documents/export?format=xml')->assertStatus(422);
        $this->actingAs($this->user)->get('/cabinet/documents/export?format=csv')->assertOk();
        $this->actingAs($this->user)->get('/cabinet/documents/export?format=xlsx')->assertOk();

        config(['search-cabinet.export' => false]);
        $this->actingAs($this->user)->get('/cabinet/documents/export?format=csv')->assertNotFound();
    }

    #[Test]
    public function stub_organization_is_not_shown_to_client(): void
    {
        config(['erp.organizations.enabled' => true]);

        $stub = Organization::factory()->create(['is_stub' => true, 'name' => 'org-uuid-заглушка']);
        $this->document(['organization_id' => $stub->id]);

        // Клиенту незачем видеть UUID вместо названия юрлица — это наша
        // внутренняя недоработка справочника, а не информация для него.
        $this->actingAs($this->user)
            ->get('/cabinet/documents')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('documents.data.0.organization', null));
    }

    #[Test]
    public function guest_is_redirected_to_login(): void
    {
        $document = $this->document();

        $this->get('/cabinet/documents')->assertRedirect();
        $this->get("/cabinet/documents/{$document->id}/download")->assertRedirect();
    }
}
