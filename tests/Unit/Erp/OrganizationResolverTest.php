<?php

namespace Tests\Unit\Erp;

use App\Models\Organization;
use App\Services\Erp\Support\OrganizationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Разрешение организации по UUID из сообщения 1С (карточка org-01).
 */
class OrganizationResolverTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): OrganizationResolver
    {
        return app(OrganizationResolver::class);
    }

    #[Test]
    public function returns_null_when_uuid_is_absent(): void
    {
        $this->assertNull($this->resolver()->resolveByUuid(null));
        $this->assertNull($this->resolver()->resolveByUuid(''));
        $this->assertNull($this->resolver()->resolveByUuid('   '));

        $this->assertDatabaseCount('organizations', 0);
    }

    #[Test]
    public function finds_existing_organization_by_uuid(): void
    {
        $organization = Organization::factory()->create(['external_id' => 'org-uuid-1']);

        $resolved = $this->resolver()->resolveByUuid('org-uuid-1');

        $this->assertSame($organization->id, $resolved->id);
        $this->assertDatabaseCount('organizations', 1);
    }

    #[Test]
    public function creates_stub_for_unknown_uuid(): void
    {
        $resolved = $this->resolver()->resolveByUuid('unknown-uuid');

        $this->assertTrue($resolved->is_stub);
        // Названия 1С не присылает — в заполнителе лежит сам UUID.
        $this->assertSame('unknown-uuid', $resolved->name);
        $this->assertDatabaseHas('organizations', [
            'external_id' => 'unknown-uuid',
            'is_stub' => true,
        ]);
    }

    #[Test]
    public function creates_stub_with_name_from_hint(): void
    {
        $resolved = $this->resolver()->resolveByUuid('uuid-2', [
            'name' => 'ООО Пекадо',
            'tax_id' => '7712345678',
        ]);

        $this->assertSame('ООО Пекадо', $resolved->name);
        $this->assertSame('7712345678', $resolved->tax_id);
        // Флаг остаётся: карточку подтверждает админ, а реквизиты 1С не присылает.
        $this->assertTrue($resolved->is_stub);
    }

    #[Test]
    public function repeated_calls_do_not_duplicate_stub(): void
    {
        $first = $this->resolver()->resolveByUuid('uuid-3');
        $second = $this->resolver()->resolveByUuid('uuid-3');

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('organizations', 1);
    }

    #[Test]
    public function hint_replaces_uuid_placeholder_in_stub_name(): void
    {
        $stub = Organization::factory()->stub()->create(['external_id' => 'uuid-4']);
        $this->assertSame('uuid-4', $stub->name);

        $resolved = $this->resolver()->resolveByUuid('uuid-4', ['name' => 'ООО Реклама']);

        $this->assertSame('ООО Реклама', $resolved->name);
        $this->assertSame($stub->id, $resolved->id);
    }

    #[Test]
    public function hint_does_not_overwrite_values_entered_by_admin(): void
    {
        $organization = Organization::factory()->create([
            'external_id' => 'uuid-5',
            'name' => 'ООО Пекадо',
            'tax_id' => '7700000000',
        ]);

        $resolved = $this->resolver()->resolveByUuid('uuid-5', [
            'name' => 'ПЕКАДО ООО (из 1С)',
            'tax_id' => '9999999999',
        ]);

        $this->assertSame('ООО Пекадо', $resolved->name);
        $this->assertSame('7700000000', $resolved->tax_id);
        $this->assertSame($organization->id, $resolved->id);
    }

    #[Test]
    public function hint_fills_only_empty_fields(): void
    {
        Organization::factory()->create([
            'external_id' => 'uuid-6',
            'name' => 'ООО Пекадо',
            'tax_id' => null,
        ]);

        $resolved = $this->resolver()->resolveByUuid('uuid-6', ['tax_id' => '7712345678']);

        $this->assertSame('7712345678', $resolved->tax_id);
    }

    #[Test]
    public function restores_soft_deleted_organization_instead_of_creating_duplicate(): void
    {
        $organization = Organization::factory()->create(['external_id' => 'uuid-7']);
        $organization->delete();

        $resolved = $this->resolver()->resolveByUuid('uuid-7');

        $this->assertSame($organization->id, $resolved->id);
        $this->assertFalse($resolved->trashed());
        $this->assertDatabaseCount('organizations', 1);
    }
}
