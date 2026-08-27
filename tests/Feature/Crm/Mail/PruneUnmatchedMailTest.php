<?php

namespace Tests\Feature\Crm\Mail;

use App\Enums\Crm\EmailStatus;
use App\Models\CrmEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Уборка папки «Без получателя».
 */
class PruneUnmatchedMailTest extends TestCase
{
    use RefreshDatabase;

    private function unmatched(int $daysAgo): CrmEmail
    {
        $letter = CrmEmail::factory()->create(['status' => EmailStatus::UNMATCHED]);
        $letter->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $letter;
    }

    #[Test]
    public function ноль_дней_убирает_всё(): void
    {
        // Раньше `?:` считал ноль пустым и подставлял умолчание: команда
        // отвечала «удалено 0» и выглядела отработавшей. Молча и опасно.
        $this->unmatched(0);
        $this->unmatched(30);

        $this->artisan('mail:prune-unmatched', ['--days' => 0])->assertSuccessful();

        $this->assertSame(0, CrmEmail::query()->where('status', EmailStatus::UNMATCHED)->count());
    }

    #[Test]
    public function без_параметра_действует_умолчание(): void
    {
        config(['mail_stream.unmatched_retention_days' => 14]);

        $fresh = $this->unmatched(2);
        $old = $this->unmatched(20);

        $this->artisan('mail:prune-unmatched')->assertSuccessful();

        $this->assertNotNull($fresh->fresh());
        $this->assertNull($old->fresh());
    }

    #[Test]
    public function сухой_прогон_ничего_не_удаляет(): void
    {
        $letter = $this->unmatched(30);

        $this->artisan('mail:prune-unmatched', ['--days' => 0, '--dry-run' => true])->assertSuccessful();

        $this->assertNotNull($letter->fresh());
    }

    #[Test]
    public function отправленные_письма_не_трогает(): void
    {
        $sent = CrmEmail::factory()->sent()->create();

        $this->artisan('mail:prune-unmatched', ['--days' => 0])->assertSuccessful();

        $this->assertNotNull($sent->fresh());
    }
}
