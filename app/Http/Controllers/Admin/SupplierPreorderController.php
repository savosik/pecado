<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderType;
use App\Jobs\SendPreorderToSupplierJob;
use App\Models\Order;
use App\Models\SupplierPreorderRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Журнал отправок предзаказов поставщику (Customer API sex-opt.ru).
 *
 * Показывает каждую попытку с ответом поставщика и позволяет переотправить
 * заказ вручную — автоматических ретраев у отправки нет по соображениям
 * идемпотентности (см. App\Jobs\SendPreorderToSupplierJob).
 */
class SupplierPreorderController extends AdminController
{
    public function index(Request $request): Response
    {
        $query = SupplierPreorderRequest::query()
            ->with(['order:id,number,erp_number,total_amount,currency_code', 'triggeredBy:id,name'])
            ->orderByDesc('created_at');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }

        if ($search = trim($request->string('search')->toString())) {
            $query->where(function ($q) use ($search) {
                $q->where('supplier_order_id', 'like', "%{$search}%")
                    ->orWhereHas('order', function ($orderQuery) use ($search) {
                        $orderQuery->where('number', 'like', "%{$search}%")
                            ->orWhere('erp_number', 'like', "%{$search}%");
                    });
            });
        }

        if ($dateFrom = $request->string('date_from')->toString()) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->string('date_to')->toString()) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $requests = $query->paginate(20)->withQueryString();

        $requests->getCollection()->transform(fn (SupplierPreorderRequest $item) => $this->presentRow($item));

        return Inertia::render('Admin/Pages/SupplierPreorders/Index', [
            'requests' => $requests,
            'filters' => $request->only(['status', 'search', 'date_from', 'date_to']),
            'stats' => $this->stats(),
            'settings' => $this->settings(),
            'can' => [
                'send' => $request->user()?->can('supplier-preorders.send') ?? false,
            ],
        ]);
    }

    public function show(SupplierPreorderRequest $supplierPreorder, Request $request): Response
    {
        $supplierPreorder->load(['order:id,number,erp_number,status,total_amount,currency_code', 'triggeredBy:id,name']);

        return Inertia::render('Admin/Pages/SupplierPreorders/Show', [
            'request' => $this->presentRow($supplierPreorder) + [
                'request_payload' => $supplierPreorder->request_payload,
                'response_payload' => $supplierPreorder->response_payload,
                'response_raw' => $supplierPreorder->response_raw,
                'skipped_items' => $supplierPreorder->skipped_items,
            ],
            'settings' => $this->settings(),
            'can' => [
                'send' => $request->user()?->can('supplier-preorders.send') ?? false,
            ],
        ]);
    }

    /**
     * Отправить (или переотправить) предзаказ поставщику.
     *
     * `force` осознанный: менеджер видит в журнале, что заказ уже создан
     * у поставщика, и всё равно требует повтора.
     */
    public function send(Request $request, Order $order): RedirectResponse
    {
        $type = $order->type->value;

        if ($type !== OrderType::PREORDER->value) {
            return back()->with('error', 'Поставщику отправляются только предзаказы.');
        }

        if (! config('services.sex_opt.preorder.enabled')) {
            return back()->with('error', 'Отправка предзаказов поставщику выключена (SUPPLIER_PREORDER_ENABLED).');
        }

        SendPreorderToSupplierJob::dispatch($order->id, $request->user()?->id, force: true);

        return back()->with('success', 'Отправка предзаказа поставщику поставлена в очередь.');
    }

    /**
     * Строка журнала в виде, готовом для таблицы.
     *
     * @return array<string, mixed>
     */
    private function presentRow(SupplierPreorderRequest $item): array
    {
        return [
            'id' => $item->id,
            'order_id' => $item->order_id,
            'order_number' => $item->order?->number,
            'order_erp_number' => $item->order?->erp_number,
            'attempt' => $item->attempt,
            'status' => $item->status,
            'status_label' => $item->statusLabel(),
            'stock' => $item->stock,
            'testmode' => $item->testmode,
            'comment' => $item->comment,
            'http_status' => $item->http_status,
            'supplier_order_id' => $item->supplier_order_id,
            'items_count' => $item->items_count,
            'skipped_count' => is_array($item->skipped_items) ? count($item->skipped_items) : 0,
            'warnings' => $item->warnings(),
            'error_message' => $item->error_message,
            'duration_ms' => $item->duration_ms,
            'triggered_by' => $item->triggeredBy?->name,
            'created_at' => $item->created_at->format('d.m.Y H:i:s'),
        ];
    }

    /**
     * Сводка по статусам — бейджи над таблицей.
     *
     * @return array<string, int>
     */
    private function stats(): array
    {
        $counts = SupplierPreorderRequest::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => (int) $counts->sum(),
            'success' => (int) $counts->get(SupplierPreorderRequest::STATUS_SUCCESS, 0),
            'rollback' => (int) $counts->get(SupplierPreorderRequest::STATUS_ROLLBACK, 0),
            'testmode' => (int) $counts->get(SupplierPreorderRequest::STATUS_TESTMODE, 0),
            'failed' => (int) $counts->get(SupplierPreorderRequest::STATUS_FAILED, 0),
        ];
    }

    /**
     * Текущие настройки интеграции — чтобы менеджер понимал, в каком режиме
     * работает отправка, не заглядывая в .env.
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'enabled' => (bool) config('services.sex_opt.preorder.enabled'),
            'stock' => (string) config('services.sex_opt.preorder.stock'),
            'testmode' => (bool) config('services.sex_opt.preorder.testmode'),
            'rollback_on_warnings' => (bool) config('services.sex_opt.preorder.rollback_on_warnings'),
        ];
    }
}
