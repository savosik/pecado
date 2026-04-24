<?php

namespace Tests\Feature\Listeners;

use App\Events\CompanyCreated;
use App\Events\CompanyUpdated;
use App\Jobs\PublishContractorToErpJob;
use App\Listeners\PublishContractorToErp;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublishContractorToErpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    #[Test]
    public function it_dispatches_on_company_created_with_tax_id(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-pub-001']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '1234567890',
            'name' => 'ООО Ромашка',
        ]);

        $listener = new PublishContractorToErp;
        $listener->handle(new CompanyCreated($company));

        Queue::assertPushed(PublishContractorToErpJob::class, function ($job) {
            return $job->payload['event'] === 'contractor.created'
                && $job->payload['partner_uuid'] === 'partner-pub-001'
                && $job->payload['tax_id'] === '1234567890'
                && $job->payload['name'] === 'ООО Ромашка'
                && isset($job->payload['message_id'])
                && isset($job->payload['timestamp']);
        });
    }

    #[Test]
    public function it_does_not_dispatch_on_company_created_without_tax_id(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-pub-002']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => null,
        ]);

        $listener = new PublishContractorToErp;
        $listener->handle(new CompanyCreated($company));

        Queue::assertNotPushed(PublishContractorToErpJob::class);
    }

    #[Test]
    public function it_dispatches_when_tax_id_filled_first_time_on_update(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-pub-003']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => null,
        ]);

        Queue::fake(); // очищаем очередь после create

        $company->update(['tax_id' => '9876543210']);

        Queue::assertPushed(PublishContractorToErpJob::class, function ($job) {
            return $job->payload['tax_id'] === '9876543210';
        });
    }

    #[Test]
    public function it_does_not_dispatch_on_company_updated_when_tax_id_already_set(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-pub-004']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '5555555555',
            'name' => 'Исходное',
        ]);

        Queue::fake();

        // Эмулируем изменение имени при уже существующем tax_id
        $company->update(['name' => 'Обновлено']);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_does_not_dispatch_when_partner_has_no_erp_id(): void
    {
        $user = User::factory()->create(['erp_id' => null]);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '7777777777',
        ]);

        Queue::fake();

        $listener = new PublishContractorToErp;
        $listener->handle(new CompanyCreated($company));

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_respects_feature_flag(): void
    {
        config(['erp.publish_contractors' => false]);

        $user = User::factory()->create(['erp_id' => 'partner-pub-005']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '1111111111',
        ]);

        $listener = new PublishContractorToErp;
        $listener->handle(new CompanyCreated($company));

        Queue::assertNotPushed(PublishContractorToErpJob::class);

        config(['erp.publish_contractors' => true]);
    }

    #[Test]
    public function payload_contains_expected_fields(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-pub-006']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '2222222222',
            'name' => 'ООО Тест',
            'legal_name' => 'ООО Тест Полное',
            'country' => 'RU',
            'tax_code' => '770101001',
            'registration_number' => '1137746123456',
            'okpo_code' => '00012345600',
            'legal_address' => 'Адрес юр.',
            'actual_address' => 'Адрес факт.',
            'phone' => '+70000000000',
            'email' => 'test@org.local',
        ]);

        $listener = new PublishContractorToErp;
        $listener->handle(new CompanyCreated($company));

        Queue::assertPushed(PublishContractorToErpJob::class, function ($job) {
            $p = $job->payload;

            return $p['event'] === 'contractor.created'
                && $p['partner_uuid'] === 'partner-pub-006'
                && $p['tax_id'] === '2222222222'
                && $p['name'] === 'ООО Тест'
                && $p['legal_name'] === 'ООО Тест Полное'
                && $p['country'] === 'RU'
                && $p['tax_code'] === '770101001'
                && $p['registration_number'] === '1137746123456'
                && $p['okpo_code'] === '00012345600'
                && $p['legal_address'] === 'Адрес юр.'
                && $p['phone'] === '+70000000000'
                && $p['email'] === 'test@org.local'
                && is_array($p['bank_accounts']);
        });
    }

    #[Test]
    public function catchup_dispatches_for_companies_without_erp_id(): void
    {
        $user = User::factory()->create(['erp_id' => 'partner-pub-007']);
        Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '3333333333',
            'erp_id' => null,
        ]);
        Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '4444444444',
            'erp_id' => null,
        ]);
        // У этой уже есть erp_id — catchup не должен её дёргать
        Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '5555555555',
            'erp_id' => 'contractor-already',
        ]);

        Queue::fake();

        PublishContractorToErp::catchupForUser($user->refresh());

        Queue::assertPushed(PublishContractorToErpJob::class, 2);
    }

    #[Test]
    public function it_does_not_republish_when_company_already_has_erp_id(): void
    {
        // Регрессия: защита от петли contractor.updated → CompanyUpdated → contractor.created.
        // Даже если withoutEvents по какой-то причине не сработает, listener не должен
        // публиковать Company, у которой уже есть erp_id от 1С.
        $user = User::factory()->create(['erp_id' => 'partner-pub-008']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '8888888888',
            'erp_id' => 'contractor-already-from-erp',
        ]);

        Queue::fake();

        $listener = new PublishContractorToErp;
        $listener->handle(new CompanyCreated($company));
        $listener->handle(new CompanyUpdated($company));

        Queue::assertNotPushed(PublishContractorToErpJob::class);
    }

    #[Test]
    public function it_does_not_republish_on_tax_id_change_when_erp_id_already_set(): void
    {
        // Кейс: Company создана на сайте, опубликована, 1С вернула UUID.
        // Затем пользователь (или другой handler) меняет tax_id — повторная
        // публикация не должна произойти, 1С уже авторитет.
        $user = User::factory()->create(['erp_id' => 'partner-pub-009']);
        $company = Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '1234567890',
            'erp_id' => 'contractor-assigned-by-erp',
        ]);

        Queue::fake();

        $company->update(['tax_id' => '0987654321']);

        Queue::assertNotPushed(PublishContractorToErpJob::class);
    }

    #[Test]
    public function catchup_does_nothing_when_user_has_no_erp_id(): void
    {
        $user = User::factory()->create(['erp_id' => null]);
        Company::factory()->create([
            'user_id' => $user->id,
            'tax_id' => '6666666666',
            'erp_id' => null,
        ]);

        Queue::fake();

        PublishContractorToErp::catchupForUser($user);

        Queue::assertNothingPushed();
    }
}
