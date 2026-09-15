<?php

namespace Tests\Unit\Services\Client\Api;

use App\Models\Company;
use App\Models\User;
use App\Services\Client\Api\CompanyContext;
use App\Services\Client\Api\CompanyRequired;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class CompanyContextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private CompanyContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->context = new CompanyContext;
    }

    #[Test]
    #[TestDox('Единственная компания берётся без company_id, даже если не отмечена основной')]
    public function single_company_is_implicit(): void
    {
        $only = Company::factory()->create(['user_id' => $this->user->id, 'is_default' => false]);

        $this->assertTrue($this->context->resolve($this->user)->is($only));
    }

    #[Test]
    #[TestDox('При нескольких компаниях берётся основная, без основной — CompanyRequired с перечнем')]
    public function default_wins_and_ambiguity_asks(): void
    {
        $a = Company::factory()->create(['user_id' => $this->user->id, 'is_default' => false, 'tax_id' => '7707083893']);
        $b = Company::factory()->create(['user_id' => $this->user->id, 'is_default' => true, 'tax_id' => '7727563778']);

        $this->assertTrue($this->context->resolve($this->user)->is($b));

        $b->update(['is_default' => false]);

        try {
            $this->context->resolve($this->user->fresh());
            $this->fail('Ожидался CompanyRequired');
        } catch (CompanyRequired $e) {
            $this->assertCount(2, $e->choices());
            $this->assertSame($a->id, $e->choices()[0]['id']);
        }
    }

    #[Test]
    #[TestDox('Явная своя компания принимается, чужая — не найдена; ИНН — только среди своих')]
    public function explicit_company_must_be_own(): void
    {
        $own = Company::factory()->create(['user_id' => $this->user->id, 'tax_id' => '7707083893']);
        $foreign = Company::factory()->create(['user_id' => User::factory()->create()->id, 'tax_id' => '7727563778']);

        $this->assertTrue($this->context->resolve($this->user, $own->id)->is($own));
        $this->assertTrue($this->context->resolve($this->user, null, '7707083893')->is($own));

        $this->expectException(ModelNotFoundException::class);
        $this->context->resolve($this->user, $foreign->id);
    }

    #[Test]
    #[TestDox('Фильтр списков: без company_id — все свои, чужой id — не найден')]
    public function filter_ids_cover_own_companies(): void
    {
        $a = Company::factory()->create(['user_id' => $this->user->id, 'tax_id' => '7707083893']);
        $b = Company::factory()->create(['user_id' => $this->user->id, 'tax_id' => '7727563778']);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $this->context->filterIds($this->user));
        $this->assertSame([$a->id], $this->context->filterIds($this->user, $a->id));

        $this->expectException(ModelNotFoundException::class);
        $this->context->filterIds($this->user, 999999);
    }
}
