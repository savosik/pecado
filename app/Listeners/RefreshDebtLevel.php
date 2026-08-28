<?php

namespace App\Listeners;

use App\Events\PartnerSettlementsChanged;
use App\Services\Debt\DebtStateService;
use Illuminate\Support\Facades\Log;

/**
 * Оплата размораживает без людей: по новым движениям регистра или балансу
 * из 1С ступень партнёра пересчитывается — только вверх (карточка debt-04).
 *
 * Синхронно в воркере очереди: сообщение уже обработано, а пересчёт по одному
 * партнёру — пара запросов. Ошибка пересчёта не должна уронить обработку
 * сообщения 1С, поэтому глушится в лог.
 */
class RefreshDebtLevel
{
    public function __construct(private readonly DebtStateService $service) {}

    public function handle(PartnerSettlementsChanged $event): void
    {
        try {
            $this->service->refresh($event->userIds);
        } catch (\Throwable $exception) {
            Log::error('Лестница долга: событийный пересчёт не удался', [
                'source' => $event->source,
                'user_ids' => $event->userIds,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
