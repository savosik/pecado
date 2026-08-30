<?php

namespace Tests\Feature\Crm\Payroll;

use App\Jobs\Payroll\RecalculatePayrollDraft;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class SalaryInvoiceMarkingTest extends TestCase
{
    use RefreshDatabase;

    private User $head;

    private User $manager;

    private PersonalManager $profile;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->head = User::factory()->create();
        $this->head->assignRole('sales-head');

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->profile = PersonalManager::factory()->create(['user_id' => $this->manager->id]);
        $this->client = User::factory()->create(['personal_manager_id' => $this->profile->id, 'name' => 'Альфа']);
    }

    private function reviewRow(string $number = '29УТ-000100'): PayrollInvoiceSettlement
    {
        return PayrollInvoiceSettlement::factory()->create([
            'shipment_id' => Shipment::factory()->create(['user_id' => $this->client->id])->id,
            'erp_number' => $number,
            'user_id' => $this->client->id,
            'personal_manager_id' => $this->profile->id,
            'shipped_on' => '2026-07-01',
            'total_amount' => 12000,
            'due_on' => '2026-07-10',
            'due_source' => PayrollInvoiceSettlement::DUE_SCHEDULE,
            'payment_status' => Shipment::PAYMENT_PAID,
            'needs_review' => true,
        ]);
    }

    #[Test]
    #[TestDox('Очередь на разметку видит только РОП; менеджеру — 403')]
    public function queue_requires_edit_permission(): void
    {
        $this->reviewRow();

        $this->actingAs($this->manager)->getJson('/crm/salary/invoices')->assertForbidden();

        $this->actingAs($this->head)
            ->getJson('/crm/salary/invoices?manager='.$this->profile->id.'&month=2026-07')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('rows.0.erp_number', '29УТ-000100')
            ->assertJsonPath('rows.0.partner_name', 'Альфа')
            ->assertJsonPath('rows.0.needs_review', true)
            ->assertJsonPath('managers.0.id', $this->profile->id);
    }

    #[Test]
    #[TestDox('Ручная дата: задержка пересчитана, очередь очищена, черновик перепланирован; снятие возвращает всё назад')]
    public function mark_and_unmark(): void
    {
        $row = $this->reviewRow();

        $this->actingAs($this->head)
            ->patchJson('/crm/salary/invoices/'.$row->id, ['settled_on' => '2026-07-17', 'comment' => 'зачёт по письму клиента'])
            ->assertOk()
            ->assertJsonPath('invoice.settled_on', '2026-07-17')
            ->assertJsonPath('invoice.settled_source', 'manual')
            ->assertJsonPath('invoice.delay_working_days', 5)   // 13–17 июля
            ->assertJsonPath('invoice.needs_review', false)
            ->assertJsonPath('invoice.manual_by', $this->head->name);

        Queue::assertPushed(RecalculatePayrollDraft::class, fn (RecalculatePayrollDraft $job): bool => $job->managerId === $this->profile->id);

        $this->actingAs($this->head)
            ->getJson('/crm/salary/invoices?mode=manual&month=2026-07')
            ->assertOk()
            ->assertJsonPath('total', 1);

        $this->actingAs($this->head)
            ->getJson('/crm/salary/invoices?mode=review')
            ->assertOk()
            ->assertJsonPath('total', 0);

        $this->actingAs($this->head)
            ->deleteJson('/crm/salary/invoices/'.$row->id.'/mark')
            ->assertOk()
            ->assertJsonPath('invoice.settled_on', null)
            ->assertJsonPath('invoice.needs_review', true);
    }

    #[Test]
    #[TestDox('Без основания дату не проставить')]
    public function comment_is_required(): void
    {
        $row = $this->reviewRow();

        $this->actingAs($this->head)
            ->patchJson('/crm/salary/invoices/'.$row->id, ['settled_on' => '2026-07-17'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);

        $this->actingAs($this->head)
            ->patchJson('/crm/salary/invoices/'.$row->id, ['settled_on' => '17.07.2026', 'comment' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['settled_on']);
    }
}
