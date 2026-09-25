<?php

namespace Tests\Feature\Api\Client;

use App\Enums\PrintedDocumentType;
use App\Models\Company;
use App\Models\Contract;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiDocumentsTest extends ClientApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('printed-documents');
        config(['documents.disk' => 'printed-documents']);
    }

    private function document(array $attrs = []): PrintedDocument
    {
        $document = PrintedDocument::factory()->create(array_merge([
            'user_id' => $this->client->id,
            'company_id' => $this->company->id,
            'disk' => 'printed-documents',
        ], $attrs));
        Storage::disk('printed-documents')->put($document->path, '%PDF-1.7 тест');

        return $document;
    }

    #[Test]
    #[TestDox('Выключенный раздел — 403 documents_disabled и allowed=false; чужой документ — 404')]
    public function documents_are_gated_and_own_only(): void
    {
        config(['documents.enabled' => false]);
        $this->api('GET', '/documents')->assertStatus(403)->assertJsonPath('errors.0.code', 'documents_disabled');
        $this->assertFalse(collect($this->api('GET', '/me')->json('data.operations'))->firstWhere('id', 'documents.list')['allowed']);

        config(['documents.enabled' => true]);
        $mine = $this->document(['type' => PrintedDocumentType::UPD, 'number' => '29УТ-000777']);
        $this->document(['type' => PrintedDocumentType::INVOICE]);
        $foreign = PrintedDocument::factory()->create(['user_id' => User::factory()->create()->id, 'company_id' => Company::factory()->create()->id]);

        $this->api('GET', '/documents')->assertOk()->assertJsonCount(2, 'data');
        $this->api('GET', '/documents?type[]=upd')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->api('GET', '/documents?number=29УТ000777')->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', '/documents/'.$foreign->id)->assertStatus(404);
        $this->api('GET', '/documents?company_id='.Company::factory()->create()->id)->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    #[TestDox('Список совпадает с кабинетом; подписанная ссылка открывает файл без токена, протухает и не подделывается')]
    public function signed_link_serves_the_file(): void
    {
        config(['documents.enabled' => true]);
        $document = $this->document();
        $this->document(['type' => PrintedDocumentType::ACT]);

        $cabinetIds = collect($this->actingAs($this->client)->get('/cabinet/documents')->viewData('page')['props']['documents']['data'])->pluck('id')->sort()->values()->all();
        $apiIds = collect($this->api('GET', '/documents')->json('data'))->pluck('id')->sort()->values()->all();
        $this->assertSame($cabinetIds, $apiIds);

        $link = $this->api('GET', "/documents/{$document->id}/link?ttl=5")->assertOk();
        $url = $link->json('data.download_url');
        $this->assertStringContainsString('signature=', $url);

        $this->get($url)->assertOk()->assertHeader('content-disposition');
        // Агенту (Accept: application/json) — 403; браузеру с протухшей ссылкой из чата
        // помощника — редирект в раздел кабинета (см. bootstrap/app.php).
        $this->getJson(str_replace('signature=', 'signature=x', $url))->assertStatus(403);

        $this->travel(6)->minutes();
        $this->getJson($url)->assertStatus(403);
        $this->actingAs($this->client)->get($url)->assertRedirect('/cabinet/documents');

        config(['documents.enabled' => false]);
        $this->travelBack();
        $this->get($url)->assertStatus(404);
    }

    #[Test]
    #[TestDox('Договоры: гейт, только видимые партнёру, сканы по подписанной ссылке')]
    public function contracts(): void
    {
        config(['contracts.cabinet_enabled' => false]);
        $this->api('GET', '/contracts')->assertStatus(403)->assertJsonPath('errors.0.code', 'contracts_disabled');

        config(['contracts.cabinet_enabled' => true]);
        Storage::fake(config('media-library.disk_name'));
        Storage::fake(Contract::attachmentsDisk());

        $visible = Contract::factory()->forCompany($this->company)->create(['number' => '№ 1', 'is_visible_in_cabinet' => true]);
        Contract::factory()->forCompany($this->company)->create(['number' => '№ скрытый', 'is_visible_in_cabinet' => false]);
        $foreign = Contract::factory()->forCompany(Company::factory()->create())->create(['is_visible_in_cabinet' => true]);

        $media = $visible->addMedia(UploadedFile::fake()->image('скан.jpg'))->toMediaCollection(CrmAttachments::COLLECTION);

        $list = $this->api('GET', '/contracts')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('№ 1', $list->json('data.0.number'));
        $this->assertStringContainsString('signature=', $list->json('data.0.files.0.url'));

        $this->api('GET', "/contracts/{$foreign->id}")->assertStatus(404);

        $url = $this->api('GET', "/contracts/{$visible->id}/files/{$media->id}/link")->assertOk()->json('data.download_url');
        $this->get($url)->assertOk();
        $this->api('GET', "/contracts/{$visible->id}/files/999999/link")->assertStatus(404);
    }
}
