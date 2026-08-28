<?php

namespace Tests\Feature\Debt;

use App\Enums\DebtLevel;
use App\Enums\PrintedDocumentType;
use App\Events\DebtLevelChanged;
use App\Listeners\SendDebtNotification;
use App\Models\Company;
use App\Models\CrmEmail;
use App\Models\DebtState;
use App\Models\PersonalManager;
use App\Models\PrintedDocument;
use App\Models\User;
use App\Support\Crm\CrmAttachments;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Письма лестницы долга: тип по ступени, ключ эпизода, акт сверки во вложении,
 * тишина в тени.
 */
class DebtNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $partner;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);

        $manager = User::factory()->create(['email' => 'manager@pecado.ru']);
        $manager->assignRole('sales-manager');
        $profile = PersonalManager::factory()->create(['user_id' => $manager->id]);

        $this->partner = User::factory()->create([
            'email' => 'client@romashka.ru',
            'personal_manager_id' => $profile->id,
        ]);
        $this->company = Company::factory()->create(['user_id' => $this->partner->id, 'name' => 'ООО Ромашка']);

        config([
            'mail_stream.enabled' => true,
            'debt.enabled' => true,
            'debt.mode' => 'live',
            'debt.live_actions' => 'mail',
        ]);
    }

    #[Test]
    public function escalation_creates_letter_of_matching_type_once(): void
    {
        $state = $this->state(DebtLevel::OVERDUE);

        $this->fire($state, DebtLevel::CLEAN, DebtLevel::OVERDUE);
        $this->fire($state, DebtLevel::CLEAN, DebtLevel::OVERDUE);

        $letters = CrmEmail::query()->where('origin_event', 'finance.debt_overdue')->get();
        $this->assertCount(1, $letters);
        $this->assertStringContainsString('lvloverdue', $letters->first()->origin_key);
        $this->assertStringContainsString('since2026-08-27', $letters->first()->origin_key);
        $this->assertSame(['client@romashka.ru'], $letters->first()->to);
        $this->assertStringContainsString('Ромашка', $letters->first()->subject);
    }

    #[Test]
    public function each_step_of_the_ladder_gets_its_own_letter(): void
    {
        $state = $this->state(DebtLevel::NO_ORDERS);

        $this->fire($state, DebtLevel::NO_PREORDERS, DebtLevel::NO_ORDERS);
        $hold = $this->state(DebtLevel::HOLD, $state);
        $this->fire($hold, DebtLevel::NO_ORDERS, DebtLevel::HOLD);

        $this->assertSame(1, CrmEmail::query()->where('origin_event', 'finance.debt_no_orders')->count());
        $this->assertSame(1, CrmEmail::query()->where('origin_event', 'finance.debt_hold')->count());
    }

    #[Test]
    public function relief_to_clean_sends_cleared_and_partial_relief_is_silent(): void
    {
        $state = $this->state(DebtLevel::NO_PREORDERS);
        $this->fire($state, DebtLevel::NO_ORDERS, DebtLevel::NO_PREORDERS);
        $this->assertSame(0, CrmEmail::query()->where('origin_event', 'like', 'finance.debt_%')->count());

        $clean = $this->state(DebtLevel::CLEAN, $state);
        $this->fire($clean, DebtLevel::NO_PREORDERS, DebtLevel::CLEAN);
        $this->assertSame(1, CrmEmail::query()->where('origin_event', 'finance.debt_cleared')->count());
    }

    #[Test]
    public function reconciliation_act_from_erp_is_attached(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('acts/act.pdf', '%PDF-1.4 test');

        PrintedDocument::factory()->create([
            'user_id' => $this->partner->id,
            'company_id' => $this->company->id,
            'type' => PrintedDocumentType::RECONCILIATION_ACT->value,
            'file_status' => PrintedDocument::FILE_STORED,
            'disk' => 'local',
            'path' => 'acts/act.pdf',
            'original_filename' => 'akt.pdf',
            'size_bytes' => 12,
        ]);

        $state = $this->state(DebtLevel::OVERDUE);
        $this->fire($state, DebtLevel::CLEAN, DebtLevel::OVERDUE);

        $letter = CrmEmail::query()->where('origin_event', 'finance.debt_overdue')->firstOrFail();
        $this->assertCount(1, $letter->getMedia(CrmAttachments::COLLECTION));
    }

    #[Test]
    public function shadow_or_disabled_mail_action_sends_nothing(): void
    {
        config(['debt.live_actions' => 'gate']);
        $state = $this->state(DebtLevel::OVERDUE);
        $this->fire($state, DebtLevel::CLEAN, DebtLevel::OVERDUE);

        $this->assertSame(0, CrmEmail::query()->count());
    }

    private function state(DebtLevel $level, ?DebtState $existing = null): DebtState
    {
        $state = $existing ?? new DebtState(['user_id' => $this->partner->id, 'company_id' => $this->company->id]);
        $state->forceFill([
            'level' => $level,
            'since' => '2026-08-27',
            'overdue_amount' => 126098,
            'overdue_total' => 126098,
            'oldest_due_date' => '2026-07-05',
            'age_days' => 54,
            'lines_count' => 3,
            'reason' => 'Просрочка 126 098 ₽',
            'dry_run' => false,
            'computed_at' => now(),
        ])->save();

        return $state->fresh(['company', 'user']);
    }

    private function fire(DebtState $state, DebtLevel $from, DebtLevel $to): void
    {
        app(SendDebtNotification::class)->handle(new DebtLevelChanged($state, $from, $to));
    }
}
