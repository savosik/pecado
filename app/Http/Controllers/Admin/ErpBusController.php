<?php

namespace App\Http\Controllers\Admin;

use App\Models\ErpBusMessage;
use App\Models\ErpProcessedMessage;
use App\Models\ErpValidationError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;

class ErpBusController extends AdminController
{
    /**
     * Очереди, которые мы отслеживаем.
     */
    private const INCOMING_QUEUES = [
        'erp_in.partners',
        'erp_in.prices',
        'erp_in.stock',
        'erp_in.orders',
        'erp_in.returns',
        'erp_in.documents',
        'erp_in.balance',
        'erp_in.segments',
        'erp_in.catalog',
    ];

    private const DLQ_QUEUES = [
        'erp_dlq.partners',
        'erp_dlq.prices',
        'erp_dlq.stock',
        'erp_dlq.orders',
        'erp_dlq.returns',
        'erp_dlq.documents',
        'erp_dlq.balance',
        'erp_dlq.segments',
        'erp_dlq.catalog',
    ];

    private const OUTGOING_QUEUES = [
        'erp_out.orders',
        'erp_out.returns',
        'erp_out.partners',
    ];

    /**
     * Отображение страницы «Шина ERP».
     */
    public function index(Request $request): Response
    {
        // 1. Статус очередей из RabbitMQ Management API
        $queues = $this->fetchQueuesFromApi();

        // 2. Обработанные сообщения с пагинацией и фильтрами
        $processedQuery = ErpProcessedMessage::query()
            ->orderByDesc('processed_at');

        if ($eventFilter = $request->get('event')) {
            $processedQuery->where('event', $eventFilter);
        }

        if ($search = $request->get('search')) {
            $processedQuery->where('message_id', 'like', "%{$search}%");
        }

        $processed = $processedQuery->paginate(20)->withQueryString();

        // 3. Ошибки (failed_jobs)
        $failedJobs = DB::table('failed_jobs')
            ->where('connection', 'like', '%rabbitmq%')
            ->orderByDesc('failed_at')
            ->paginate(10, ['*'], 'failed_page')
            ->withQueryString();

        // 4. Статистика по типам событий
        $eventStats = ErpProcessedMessage::selectRaw('event, COUNT(*) as count, MAX(processed_at) as last_at')
            ->groupBy('event')
            ->orderByDesc('count')
            ->get()
            ->map(fn ($s) => [
                'event' => $s->event,
                'count' => $s->count,
                'last_at' => $s->last_at,
            ]);

        // 5. Все уникальные типы событий (для фильтра)
        $eventTypes = ErpProcessedMessage::distinct()->pluck('event')->sort()->values();

        // 6. Ошибки валидации JSON Schema
        $validationErrors = ErpValidationError::query()
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'validation_page')
            ->withQueryString();

        $validationErrorsCount = ErpValidationError::count();

        // 7. Количество записей в логе сообщений (для бейджа)
        $busMessagesCount = ErpBusMessage::count();
        $busLoggingEnabled = (bool) config('erp.bus_logging_enabled', false);

        return Inertia::render('Admin/Pages/ErpBus/Index', [
            'queues' => $queues,
            'processed' => $processed,
            'failedJobs' => $failedJobs,
            'eventStats' => $eventStats,
            'eventTypes' => $eventTypes,
            'validationErrors' => $validationErrors,
            'validationErrorsCount' => $validationErrorsCount,
            'busMessagesCount' => $busMessagesCount,
            'busLoggingEnabled' => $busLoggingEnabled,
            'filters' => [
                'event' => $request->get('event', ''),
                'search' => $request->get('search', ''),
            ],
        ]);
    }

    /**
     * Лог сообщений шины ERP.
     */
    public function messages(Request $request): Response
    {
        $query = ErpBusMessage::query()->orderByDesc('created_at');

        // Фильтры
        if ($direction = $request->get('direction')) {
            $query->where('direction', $direction);
        }

        if ($event = $request->get('event')) {
            $query->where('event', $event);
        }

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('message_id', 'like', "%{$search}%")
                  ->orWhere('event', 'like', "%{$search}%")
                  ->orWhere('routing_key', 'like', "%{$search}%");
            });
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->where('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $query->where('created_at', '<=', $dateTo . ' 23:59:59');
        }

        $messages = $query->paginate(30)->withQueryString();

        // Уникальные типы событий для фильтра
        $eventTypes = ErpBusMessage::distinct()->pluck('event')->sort()->values();

        return Inertia::render('Admin/Pages/ErpBus/Messages', [
            'messages' => $messages,
            'eventTypes' => $eventTypes,
            'filters' => [
                'direction' => $request->get('direction', ''),
                'event'     => $request->get('event', ''),
                'status'    => $request->get('status', ''),
                'search'    => $request->get('search', ''),
                'date_from' => $request->get('date_from', ''),
                'date_to'   => $request->get('date_to', ''),
            ],
        ]);
    }

    /**
     * Просмотр отдельного сообщения.
     */
    public function showMessage(ErpBusMessage $message): Response
    {
        return Inertia::render('Admin/Pages/ErpBus/ShowMessage', [
            'message' => $message,
        ]);
    }

    /**
     * Очистить лог сообщений шины.
     */
    public function clearMessages(): \Illuminate\Http\RedirectResponse
    {
        ErpBusMessage::truncate();

        return back()->with('success', 'Лог сообщений очищен');
    }

    /**
     * Очистить лог ошибок валидации.
     */
    public function clearValidationErrors(): \Illuminate\Http\RedirectResponse
    {
        ErpValidationError::truncate();

        return back()->with('success', 'Лог ошибок валидации очищен');
    }

    /**
     * Получить данные об очередях через RabbitMQ Management HTTP API.
     */
    private function fetchQueuesFromApi(): array
    {
        $host = config('queue.connections.rabbitmq.hosts.0.host', 'rabbitmq');
        $mgmtUser = config('queue.connections.rabbitmq.management.user', 'guest');
        $mgmtPassword = config('queue.connections.rabbitmq.management.password', 'guest');
        $mgmtPort = config('queue.connections.rabbitmq.management.port', 15672);

        try {
            $response = Http::withBasicAuth($mgmtUser, $mgmtPassword)
                ->timeout(5)
                ->get("http://{$host}:{$mgmtPort}/api/queues");

            if (! $response->successful()) {
                return ['error' => "API вернул статус: {$response->status()}"];
            }

            $allQueues = $response->json();
            $queueMap = [];
            foreach ($allQueues as $q) {
                $queueMap[$q['name']] = $q;
            }

            return [
                'incoming' => $this->mapQueues(self::INCOMING_QUEUES, $queueMap),
                'dlq' => $this->mapQueues(self::DLQ_QUEUES, $queueMap),
                'outgoing' => $this->mapQueues(self::OUTGOING_QUEUES, $queueMap),
            ];
        } catch (\Exception $e) {
            return ['error' => "Ошибка подключения: {$e->getMessage()}"];
        }
    }

    /**
     * Маппинг очередей в формат для фронтенда.
     */
    private function mapQueues(array $queueNames, array $queueMap): array
    {
        return array_map(function (string $name) use ($queueMap) {
            $q = $queueMap[$name] ?? null;

            return [
                'name' => $name,
                'ready' => $q['messages_ready'] ?? 0,
                'unacked' => $q['messages_unacknowledged'] ?? 0,
                'total' => ($q['messages_ready'] ?? 0) + ($q['messages_unacknowledged'] ?? 0),
                'consumers' => $q['consumers'] ?? 0,
                'exists' => $q !== null,
            ];
        }, $queueNames);
    }
}
