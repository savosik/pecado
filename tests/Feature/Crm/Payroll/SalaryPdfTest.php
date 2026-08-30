<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PersonalManager;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Feature\Crm\Concerns\RestrictsManagersToOwnClients;
use Tests\TestCase;

/**
 * Печатные формы зарплаты: лист и разбор.
 *
 * Проверяем не вёрстку, а то, что документ вообще собирается на живом снимке и
 * не утекает чужому: PDF рендерится целиком, поэтому любая опечатка в шаблоне
 * или отсутствующий ключ уронят генерацию — тест ловит это раньше менеджера.
 */
class SalaryPdfTest extends TestCase
{
    use RefreshDatabase;
    use RestrictsManagersToOwnClients;

    private User $manager;

    private PersonalManager $managerProfile;

    private PersonalManager $otherProfile;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->restrictManagersToOwnClients();

        $this->manager = User::factory()->create();
        $this->manager->assignRole('sales-manager');
        $this->managerProfile = PersonalManager::factory()->create(['user_id' => $this->manager->id, 'name' => 'Курочкина']);
        User::factory()->create(['personal_manager_id' => $this->managerProfile->id]);

        $colleague = User::factory()->create();
        $colleague->assignRole('sales-manager');
        $this->otherProfile = PersonalManager::factory()->create(['user_id' => $colleague->id, 'name' => 'Сухов']);
    }

    #[Test]
    #[TestDox('Расчётный лист скачивается в PDF')]
    public function payslip_downloads(): void
    {
        $response = $this->actingAs($this->manager)->get('/crm/salary/payslip?month='.now()->format('Y-m'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    #[TestDox('Разбор с пояснениями собирается на живом снимке')]
    public function explained_downloads(): void
    {
        $response = $this->actingAs($this->manager)->get('/crm/salary/explained?month='.now()->format('Y-m'));

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    #[Test]
    #[TestDox('Чужой менеджер в адресе игнорируется — придёт свой расчёт')]
    public function foreign_manager_is_ignored(): void
    {
        $this->actingAs($this->manager)
            ->get('/crm/salary/payslip?manager='.$this->otherProfile->id)
            ->assertOk();

        // Снимок завёлся только свой: чужой расчёт не считался и не показан.
        $this->assertDatabaseMissing('payroll_calculations', ['personal_manager_id' => $this->otherProfile->id]);
        $this->assertDatabaseHas('payroll_calculations', ['personal_manager_id' => $this->managerProfile->id]);
    }

    #[Test]
    #[TestDox('Исключённому из расчёта печатать нечего')]
    public function excluded_manager_gets_404(): void
    {
        $this->managerProfile->forceFill(['payroll_enabled' => false])->save();

        $this->actingAs($this->manager)->get('/crm/salary/payslip')->assertNotFound();
        $this->actingAs($this->manager)->get('/crm/salary/explained')->assertNotFound();
    }

    #[Test]
    #[TestDox('Без права crm-salary.view формы недоступны')]
    public function outsider_is_denied(): void
    {
        $outsider = User::factory()->create();

        // Не-сотрудника CRM разворачивает раньше проверки права — редиректом из /crm.
        $this->actingAs($outsider)->get('/crm/salary/payslip')->assertRedirect();
    }
}
