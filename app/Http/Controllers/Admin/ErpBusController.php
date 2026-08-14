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
        'erp_in.contractors',
        'erp_in.prices',
        'erp_in.stock',
        'erp_in.orders',
        'erp_in.returns',
        'erp_in.documents',
        'erp_in.payments',
        'erp_in.warehouse',
        'erp_in.balance',
        'erp_in.catalog',
        'erp_in.promotions',
    ];

    private const DLQ_QUEUES = [
        'erp_dlq.partners',
        'erp_dlq.contractors',
        'erp_dlq.prices',
        'erp_dlq.stock',
        'erp_dlq.orders',
        'erp_dlq.returns',
        'erp_dlq.documents',
        'erp_dlq.payments',
        'erp_dlq.warehouse',
        'erp_dlq.balance',
        'erp_dlq.catalog',
        'erp_dlq.promotions',
    ];

    private const OUTGOING_QUEUES = [
        'erp_out.orders',
        'erp_out.returns',
        'erp_out.partners',
        'erp_out.contractors',
    ];

    /**
     * Очереди, наполняемые через RabbitMQ Shovel с внешних ESB.
     * Воркеров со стороны Laravel у них нет — потребители: сайт и 1С Pecado.
     */
    private const EXTERNAL_QUEUES = [
        'external.remains_for_erp',
        'external.orders_from_andrey_for_erp',
    ];

    /**
     * Отображение страницы «Шина ERP».
     */
    public function index(Request $request): Response
    {
        // 1. Статус очередей из RabbitMQ Management API — отложенная загрузка
        //    (Inertia::defer): страница отдаётся сразу, а обращение к RabbitMQ
        //    Management API (таймаут до 5с) выполняется отдельным запросом, не
        //    блокируя первичный рендер.

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

        // 8. v15.4: ошибки обработки — сообщение валидно по схеме, но обработать
        //    его не удалось (например, order.updated по заказу, которого нет,
        //    и данных для восстановления не хватает).
        $processingErrors = ErpBusMessage::query()
            ->where('status', 'failed')
            ->orderByDesc('created_at')
            ->paginate(15, ['id', 'direction', 'routing_key', 'event', 'message_id', 'error_message', 'created_at'], 'processing_page')
            ->withQueryString();

        $processingErrorsCount = ErpBusMessage::where('status', 'failed')->count();

        // 9. v15.4: восстановленные сущности — обработано, но 1С потеряла событие
        //    создания. Не ошибка сайта, но повод разбираться на стороне 1С.
        $recoveredCount = ErpBusMessage::where('status', 'recovered')->count();

        // 10. v15.16.0: отброшенные устаревшие сообщения — доехали после более
        //     свежего по тому же документу. Не ошибка, но постоянный рост счётчика
        //     означает, что 1С шлёт лишние сообщения либо порядок систематически рвётся.
        $staleCount = ErpBusMessage::where('status', 'stale')->count();

        // 11. Рассинхрон каталога: позиции заказов, чей товар не нашёлся по UUID.
        //     Это не недобор и не ошибка обработки — сообщение принято, но строка
        //     осталась без привязки к товару. Молча копятся, поэтому счётчик.
        $unknownProductItemsCount = \App\Models\OrderItem::whereNull('product_id')->count();

        return Inertia::render('Admin/Pages/ErpBus/Index', [
            'queues' => Inertia::defer(fn () => $this->fetchQueuesFromApi()),
            'processed' => $processed,
            'failedJobs' => $failedJobs,
            'eventStats' => $eventStats,
            'eventTypes' => $eventTypes,
            'validationErrors' => $validationErrors,
            'validationErrorsCount' => $validationErrorsCount,
            'processingErrors' => $processingErrors,
            'processingErrorsCount' => $processingErrorsCount,
            'recoveredCount' => $recoveredCount,
            'staleCount' => $staleCount,
            'unknownProductItemsCount' => $unknownProductItemsCount,
            'busMessagesCount' => $busMessagesCount,
            'busLoggingEnabled' => $busLoggingEnabled,
            // Счётчики выше считаются по таблице, а её глубина ограничена
            // ретенцией — без этой подписи цифры читались бы как «за всё время».
            'busRetentionDays' => (int) config('erp.bus_retention_days', 14),
            'busArchiveEnabled' => (bool) config('erp.bus_archive.enabled', false),
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
        $query = ErpBusMessage::query()
            ->select(['id', 'direction', 'routing_key', 'event', 'message_id', 'status', 'created_at'])
            ->orderByDesc('created_at');

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
            $query->where('created_at', '<=', $dateTo.' 23:59:59');
        }

        $messages = $query->paginate(30)->withQueryString();

        // Уникальные типы событий для фильтра
        $eventTypes = ErpBusMessage::distinct()->pluck('event')->sort()->values();

        return Inertia::render('Admin/Pages/ErpBus/Messages', [
            'messages' => $messages,
            'eventTypes' => $eventTypes,
            'filters' => [
                'direction' => $request->get('direction', ''),
                'event' => $request->get('event', ''),
                'status' => $request->get('status', ''),
                'search' => $request->get('search', ''),
                'date_from' => $request->get('date_from', ''),
                'date_to' => $request->get('date_to', ''),
            ],
        ]);
    }

    /**
     * Просмотр отдельного сообщения.
     *
     * Без implicit binding намеренно: сообщения старше ретенции уезжают в
     * холодное хранилище, и ссылка из старого письма или заметки дала бы голый
     * 404 — непонятно, потеряно сообщение или его никогда не было. Вместо этого
     * возвращаем внятное объяснение, где его теперь искать.
     */
    public function showMessage(int $message): Response|\Illuminate\Http\RedirectResponse
    {
        $found = ErpBusMessage::find($message);

        if (! $found) {
            $days = (int) config('erp.bus_retention_days', 14);

            return redirect()
                ->route('admin.erp-bus.messages')
                ->with('error', config('erp.bus_archive.enabled')
                    ? "Сообщение №{$message} не найдено: лог хранится {$days} дн., более старые выгружены в архив холодного хранилища."
                    : "Сообщение №{$message} не найдено: лог хранится {$days} дн., более старые удалены.");
        }

        return Inertia::render('Admin/Pages/ErpBus/ShowMessage', [
            'message' => $found,
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
     * Очистить журнал обработанных сообщений и статистику (синхронно).
     */
    public function clearProcessed(): \Illuminate\Http\RedirectResponse
    {
        ErpProcessedMessage::truncate();

        return back()->with('success', 'Журнал обработанных сообщений очищен');
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
                'external' => $this->mapQueues(self::EXTERNAL_QUEUES, $queueMap),
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
