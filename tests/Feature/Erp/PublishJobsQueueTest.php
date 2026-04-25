<?php

namespace Tests\Feature\Erp;

use App\Jobs\PublishContractorToErpJob;
use App\Jobs\PublishOrderToErpJob;
use App\Jobs\PublishReturnToErpJob;
use App\Jobs\PublishUserToErpJob;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Регрессия: все исходящие publish-job-ы (Сайт → 1С) должны попадать в
 * выделенную очередь erp_publish, а не в default.
 *
 * До v13.x они шли в default, который laravel-worker слушал совместно с
 * catalog-media. При массовом импорте медиа очередь catalog-media полностью
 * блокировала default — критичные contractor.created/partner.created/order.created
 * зависали по 15+ минут (см. инцидент с компанией 820).
 *
 * Теперь у каждого Publish*ToErpJob выставлен $queue = 'erp_publish' и под
 * эту очередь поднята отдельная supervisor-программа erp-publish-worker.
 */
class PublishJobsQueueTest extends TestCase
{
    public static function publishJobsProvider(): array
    {
        return [
            'contractor' => [PublishContractorToErpJob::class],
            'user' => [PublishUserToErpJob::class],
            'order' => [PublishOrderToErpJob::class],
            'return' => [PublishReturnToErpJob::class],
        ];
    }

    #[Test]
    #[DataProvider('publishJobsProvider')]
    public function publish_job_lands_on_erp_publish_queue(string $jobClass): void
    {
        Queue::fake();

        $jobClass::dispatch(['event' => 'test.event']);

        Queue::assertPushedOn('erp_publish', $jobClass);
    }
}
