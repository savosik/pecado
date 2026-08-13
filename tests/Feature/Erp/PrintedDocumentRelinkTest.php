<?php

namespace Tests\Feature\Erp;

use App\Models\Company;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PrintedDocument;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Доклейка связей печатных форм, приехавших раньше своих сторон (v16.1.0).
 *
 * Порядок доставки очередей не гарантирован, и печатная форма регулярно приходит
 * до контрагента или реализации. Терять её нельзя — 1С формы не хранит, поэтому
 * связи проставляются задним числом.
 */
class PrintedDocumentRelinkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function contractor_arriving_later_gets_linked(): void
    {
        $document = PrintedDocument::factory()->unmatched()->create([
            'contractor_uuid' => 'contractor-late',
            'partner_uuid' => 'partner-late',
        ]);

        $this->artisan('documents:relink')->assertSuccessful();
        $this->assertNull($document->fresh()->company_id, 'Пока контрагента нет, связывать не с чем');

        $user = User::factory()->create(['erp_id' => 'partner-late']);
        $company = Company::factory()->create(['user_id' => $user->id, 'erp_id' => 'contractor-late']);

        $this->artisan('documents:relink')->assertSuccessful();

        $document->refresh();
        $this->assertSame($company->id, $document->company_id);
        $this->assertSame($user->id, $document->user_id);
    }

    #[Test]
    public function order_shipment_and_organization_get_linked(): void
    {
        $document = PrintedDocument::factory()->unmatched()->create([
            'contractor_uuid' => null,
            'partner_uuid' => null,
            'organization_uuid' => 'org-late',
            'order_uuid' => 'order-late',
            'shipment_uuid' => 'shipment-late',
        ]);

        $organization = Organization::factory()->create(['external_id' => 'org-late']);
        $order = Order::factory()->create(['uuid' => 'order-late']);
        $shipment = Shipment::factory()->create(['uuid' => 'shipment-late']);

        $this->artisan('documents:relink')->assertSuccessful();

        $document->refresh();
        $this->assertSame($organization->id, $document->organization_id);
        $this->assertSame($order->id, $document->order_id);
        $this->assertSame($shipment->id, $document->shipment_id);
    }

    #[Test]
    public function relink_is_idempotent_and_does_not_touch_linked_documents(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-1']);
        $company = Company::factory()->create(['user_id' => $user->id, 'erp_id' => 'contractor-1']);
        $other = Company::factory()->create(['user_id' => $user->id, 'erp_id' => 'contractor-2']);

        // Связь уже проставлена и указывает НЕ на того контрагента, чей UUID
        // лежит в сыром поле: перезаписывать её команда не должна — данные
        // могли поправить руками.
        $document = PrintedDocument::factory()->create([
            'company_id' => $other->id,
            'contractor_uuid' => 'contractor-1',
        ]);

        $this->artisan('documents:relink')->assertSuccessful();
        $this->artisan('documents:relink')->assertSuccessful();

        $this->assertSame($other->id, $document->fresh()->company_id);
        $this->assertNotSame($company->id, $document->fresh()->company_id);
    }

    #[Test]
    public function unresolvable_uuid_does_not_loop_forever(): void
    {
        // Строка, чей UUID не резолвится никогда: курсор обязан пройти её
        // и остановиться, а не крутиться на первом батче.
        PrintedDocument::factory()->unmatched()->create(['contractor_uuid' => 'never-arrives']);

        $this->artisan('documents:relink --chunk=1')->assertSuccessful();

        $this->assertTrue(true, 'Команда завершилась, а не зациклилась');
    }

    #[Test]
    public function trashed_documents_are_relinked_too(): void
    {
        // Форму отозвали до того, как приехал контрагент. Связь всё равно нужна:
        // 1С может снять пометку удаления, и документ вернётся клиенту.
        $document = PrintedDocument::factory()->unmatched()->create(['contractor_uuid' => 'contractor-trashed']);
        $document->delete();

        $user = User::factory()->create();
        $company = Company::factory()->create(['user_id' => $user->id, 'erp_id' => 'contractor-trashed']);

        $this->artisan('documents:relink')->assertSuccessful();

        $this->assertSame($company->id, PrintedDocument::withTrashed()->find($document->id)->company_id);
    }
}
