<?php

namespace App\Services\Crm;

use App\Models\User;
use App\Services\Analytics\AnalyticsContext;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ShipmentAnalyticsService;
use Carbon\CarbonImmutable;

/**
 * Аналитика одного клиента: тренд, разрезы и последние документы.
 *
 * Один и тот же ответ нужен двум потребителям — провалу в клиента на «грядках»
 * и операции `client.sales` агентского API. Своей арифметики здесь нет: всё
 * считает {@see ShipmentAnalyticsService} с контекстом из одного клиента,
 * документы отдаёт {@see ClientTimelineService}. Второго движка выручки в проекте
 * нет и не появляется.
 */
class ClientInsightService
{
    public function __construct(
        private readonly ShipmentAnalyticsService $analytics,
        private readonly ClientTimelineService $timeline,
    ) {}

    /**
     * @param  int  $months  глубина периода
     * @param  bool  $withDocuments  добавить последние заказы и реализации
     * @return array<string, mixed>
     */
    public function forClient(User $client, User $viewer, int $months = 12, bool $withDocuments = false): array
    {
        $to = CarbonImmutable::now()->endOfDay();
        $from = $to->subMonthsNoOverflow(max(1, $months))->startOfMonth();

        // Бизнес-дата — erp_created_at: историю заказов импортировали в мае 2026,
        // и разрез по месяцам, построенный на created_at, показал бы всю историю
        // одним всплеском.
        $context = AnalyticsContext::forScope(
            [(int) $client->getKey()],
            AnalyticsContext::DATE_ERP,
            null,
        );
        $filters = new AnalyticsFilters(dateFrom: $from, dateTo: $to);

        $payload = [
            'client' => [
                'id' => (int) $client->getKey(),
                'name' => $client->display_name,
                'email' => $client->email,
                'phone' => $client->phone,
            ],
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'months' => $months,
            ],
            'metrics' => $this->analytics->metrics($context, $filters),
            'timeline' => $this->analytics->timeSeries($context, $filters),
            'brands' => $this->analytics->byBrand($context, $filters, 15)->all(),
            'categories' => $this->analytics->byCategory($context, $filters, 15)->all(),
            'products' => $this->analytics->byProduct($context, $filters, 20)->all(),
        ];

        if ($withDocuments) {
            // Через ленту, а не своим запросом к заказам: право видеть документ
            // и его форма для показа уже описаны там, и вторая выборка разошлась бы
            // с лентой карточки клиента.
            $payload['documents'] = $this->timeline
                ->forClient($client, $viewer, 10, ['order', 'shipment'], null)
                ->items();
        }

        return $payload;
    }
}
