<?php

namespace Tests\Feature\Admin;

use App\Models\ErpBusMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * v15.4: блок «Ошибки обработки» на странице «Шина ERP».
 *
 * Ошибка обработки должна быть видна администратору рядом с ошибками валидации —
 * до v15.4 такие сбои не отображались нигде.
 */
class ErpBusProcessingErrorsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    private function logMessage(string $status, ?string $error, string $messageId): void
    {
        ErpBusMessage::create([
            'direction' => 'incoming',
            'routing_key' => 'erp_in.orders',
            'event' => 'order.updated',
            'message_id' => $messageId,
            'payload' => ['event' => 'order.updated', 'uuid' => 'u-'.$messageId],
            'status' => $status,
            'error_message' => $error,
        ]);
    }

    #[Test]
    public function index_exposes_processing_errors_and_recovered_count(): void
    {
        $this->logMessage('failed', 'order.updated по несуществующему заказу 29УТ-009892', 'msg-failed');
        $this->logMessage('recovered', 'Заказ 29УТ-010318 восстановлен', 'msg-recovered');
        $this->logMessage('success', null, 'msg-ok');

        $this->actingAs($this->admin)
            ->get(route('admin.erp-bus.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // false: страницы админки лежат вне resources/js/Pages,
                // и view-finder Inertia их не резолвит.
                ->component('Admin/Pages/ErpBus/Index', false)
                ->where('processingErrorsCount', 1)
                ->where('recoveredCount', 1)
                ->has('processingErrors.data', 1)
                ->where('processingErrors.data.0.message_id', 'msg-failed')
                ->where('processingErrors.data.0.error_message', 'order.updated по несуществующему заказу 29УТ-009892')
            );
    }

    #[Test]
    public function index_shows_no_processing_errors_when_bus_is_clean(): void
    {
        $this->logMessage('success', null, 'msg-ok');

        $this->actingAs($this->admin)
            ->get(route('admin.erp-bus.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('processingErrorsCount', 0)
                ->where('recoveredCount', 0)
                ->has('processingErrors.data', 0)
            );
    }

    /**
     * Лог сообщений должен уметь фильтроваться по новому статусу.
     */
    #[Test]
    public function messages_can_be_filtered_by_recovered_status(): void
    {
        $this->logMessage('recovered', 'Заказ 29УТ-010318 восстановлен', 'msg-recovered');
        $this->logMessage('success', null, 'msg-ok');

        $this->actingAs($this->admin)
            ->get(route('admin.erp-bus.messages', ['status' => 'recovered']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('messages.data', 1)
                ->where('messages.data.0.message_id', 'msg-recovered')
            );
    }
}
