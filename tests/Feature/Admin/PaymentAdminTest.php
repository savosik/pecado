<?php

namespace Tests\Feature\Admin;

use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Платежи в админке.
 *
 * Главное, что проверяется: реквизиты 1С не редактируются. Не «поле спрятано
 * в интерфейсе», а маршрута нет вовсе — иначе следующий payment.updated молча
 * затрёт правку, и расхождение с учётной системой найдут не сразу.
 */
class PaymentAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('super-admin');
    }

    private function payment(array $attributes = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'number' => '29УТ-002488',
            'amount' => 2325.20,
            'currency_code' => 'RUB',
        ], $attributes));
    }

    #[Test]
    public function index_and_show_are_available_with_view_permission(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->admin)
            ->get(route('admin.payments.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Payments/Index')
                ->has('payments.data', 1));

        $this->actingAs($this->admin)
            ->get(route('admin.payments.show', $payment->id))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Pages/Payments/Show')
                ->where('payment.number', '29УТ-002488'));
    }

    #[Test]
    public function user_without_admin_access_is_denied(): void
    {
        $stranger = User::factory()->create();

        // EnsureUserIsAdmin отправляет на главную с сообщением, а не отдаёт 403.
        $this->actingAs($stranger)
            ->get(route('admin.payments.index'))
            ->assertRedirect('/');
    }

    #[Test]
    public function admin_without_payments_permission_is_forbidden(): void
    {
        // Роль с доступом в админку, но без права на платежи: раздел должен
        // закрываться правом, а не только пунктом меню.
        $contentManager = User::factory()->create();
        $contentManager->assignRole('content-manager');

        $this->actingAs($contentManager)
            ->get(route('admin.payments.index'))
            ->assertForbidden();
    }

    #[Test]
    public function erp_fields_have_no_update_route(): void
    {
        $payment = $this->payment();

        // Ни одного маршрута правки реквизитов быть не должно.
        foreach (['admin.payments.update', 'admin.payments.edit', 'admin.payments.store'] as $name) {
            $this->assertFalse(
                app('router')->has($name),
                "Маршрут {$name} не должен существовать: мастер реквизитов — 1С"
            );
        }

        // PATCH на карточку тоже не проходит — метода нет.
        $this->actingAs($this->admin)
            ->patch('/admin/payments/'.$payment->id, ['number' => 'ПОДМЕНА'])
            ->assertStatus(405);

        $this->assertSame('29УТ-002488', $payment->fresh()->number);
    }

    #[Test]
    public function comment_is_the_only_editable_field(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->admin)
            ->patch(route('admin.payments.comment', $payment->id), [
                'comment' => 'Разнесение подтвердил бухгалтер',
            ])
            ->assertRedirect();

        $this->assertSame('Разнесение подтвердил бухгалтер', $payment->fresh()->comment);
    }

    #[Test]
    public function comment_longer_than_limit_is_rejected_in_russian(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->admin)
            ->patch(route('admin.payments.comment', $payment->id), [
                'comment' => str_repeat('а', 2001),
            ])
            ->assertSessionHasErrors(['comment' => 'Комментарий не должен быть длиннее 2000 символов.']);
    }

    #[Test]
    public function delete_restore_and_force_delete_work(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->admin)
            ->delete(route('admin.payments.destroy', $payment->id))
            ->assertRedirect();
        $this->assertSoftDeleted('payments', ['id' => $payment->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.payments.restore', $payment->id))
            ->assertRedirect();
        $this->assertNull($payment->fresh()->deleted_at);

        $payment->delete();
        $this->actingAs($this->admin)
            ->delete(route('admin.payments.force-delete', $payment->id))
            ->assertRedirect();
        $this->assertDatabaseMissing('payments', ['id' => $payment->id]);
    }

    #[Test]
    public function trashed_filter_shows_only_deleted(): void
    {
        $this->payment(['number' => 'ЖИВОЙ']);
        $this->payment(['number' => 'УДАЛЁННЫЙ'])->delete();

        $this->actingAs($this->admin)
            ->get(route('admin.payments.index', ['trashed' => 1]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'УДАЛЁННЫЙ')
                ->where('trashedCount', 1));
    }

    #[Test]
    public function search_finds_payment_by_bank_number(): void
    {
        $this->payment(['number' => 'ПЕРВЫЙ', 'bank_number' => '9202']);
        $this->payment(['number' => 'ВТОРОЙ', 'bank_number' => '7777']);

        $this->actingAs($this->admin)
            ->get(route('admin.payments.index', ['search' => '9202']))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('payments.data', 1)
                ->where('payments.data.0.number', 'ПЕРВЫЙ'));
    }
}
