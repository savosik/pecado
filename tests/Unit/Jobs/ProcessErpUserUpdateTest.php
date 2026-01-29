<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessErpUserUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProcessErpUserUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_user_erp_id_and_status(): void
    {
        $user = User::factory()->create([
            'erp_id' => null,
            'status' => 'processing',
        ]);

        $payload = [
            'user' => ['id' => $user->id],
            'erp_id' => 'erp-uuid-12345',
            'status' => 'confirmed',
        ];

        $job = new ProcessErpUserUpdate($payload);
        $job->handle();

        $user->refresh();

        $this->assertEquals('erp-uuid-12345', $user->erp_id);
        $this->assertEquals('confirmed', $user->status);
    }

    #[Test]
    public function it_updates_only_erp_id_when_status_not_provided(): void
    {
        $user = User::factory()->create([
            'erp_id' => null,
            'status' => 'processing',
        ]);

        $payload = [
            'user' => ['id' => $user->id],
            'erp_id' => 'erp-uuid-67890',
        ];

        $job = new ProcessErpUserUpdate($payload);
        $job->handle();

        $user->refresh();

        $this->assertEquals('erp-uuid-67890', $user->erp_id);
        $this->assertEquals('processing', $user->status);
    }

    #[Test]
    public function it_does_nothing_when_user_id_missing(): void
    {
        $payload = [
            'erp_id' => 'erp-uuid-12345',
            'status' => 'confirmed',
        ];

        $job = new ProcessErpUserUpdate($payload);
        $job->handle();

        // No exception should be thrown
        $this->assertTrue(true);
    }

    #[Test]
    public function it_does_nothing_when_erp_id_missing(): void
    {
        $user = User::factory()->create([
            'erp_id' => null,
            'status' => 'processing',
        ]);

        $payload = [
            'user' => ['id' => $user->id],
            'status' => 'confirmed',
        ];

        $job = new ProcessErpUserUpdate($payload);
        $job->handle();

        $user->refresh();

        // Should remain unchanged
        $this->assertNull($user->erp_id);
        $this->assertEquals('processing', $user->status);
    }

    #[Test]
    public function it_does_nothing_when_user_not_found(): void
    {
        $payload = [
            'user' => ['id' => 99999],
            'erp_id' => 'erp-uuid-12345',
            'status' => 'confirmed',
        ];

        $job = new ProcessErpUserUpdate($payload);
        $job->handle();

        // No exception should be thrown
        $this->assertTrue(true);
    }
}
