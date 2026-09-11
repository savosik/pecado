<?php

namespace App\Http\Controllers\Crm;

use App\Events\Payroll\PayrollInputsChanged;
use App\Models\PayrollInvoiceSettlement;
use App\Services\Motivation\InvoiceReviewQueueService;
use App\Services\Payroll\Invoices\PayrollInvoiceSettlementProjector;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Очередь разметки накладных с ценой в рублях (mot-37; форма B8).
 *
 * Простановка и снятие даты — через тот же проектор, что в действующей системе:
 * ручная дата приоритетнее восстановленной, ночная пересборка её не трогает.
 */
class MotivationInvoicesController extends CrmController
{
    public function __construct(
        private readonly InvoiceReviewQueueService $queue,
        private readonly PayrollInvoiceSettlementProjector $projector,
    ) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Crm/Pages/Motivation/Invoices', $this->payload($request));
    }

    public function data(Request $request): JsonResponse
    {
        return response()->json($this->payload($request));
    }

    public function mark(Request $request, PayrollInvoiceSettlement $invoice): JsonResponse
    {
        $data = $request->validate([
            'settled_on' => ['required', 'date_format:Y-m-d'],
            'comment' => ['required', 'string', 'max:255'],
        ], [
            'settled_on.required' => 'Укажите дату оплаты.',
            'settled_on.date_format' => 'Дата должна быть в формате ГГГГ-ММ-ДД.',
            'comment.required' => 'Укажите основание — откуда известна дата.',
            'comment.max' => 'Основание не может быть длиннее 255 символов.',
        ]);

        $row = $this->projector->markManual($invoice, CarbonImmutable::parse($data['settled_on']), $data['comment'], $this->crmActor($request));
        $this->notify($row);

        return response()->json(['ok' => true, 'message' => 'Дата оплаты проставлена; черновик пересчитается.'] + $this->payload($request));
    }

    public function unmark(Request $request, PayrollInvoiceSettlement $invoice): JsonResponse
    {
        $row = $this->projector->clearManual($invoice);
        $this->notify($row);

        return response()->json(['ok' => true, 'message' => 'Ручная дата снята.'] + $this->payload($request));
    }

    private function notify(PayrollInvoiceSettlement $row): void
    {
        if ($row->personal_manager_id === null) {
            return;
        }

        $months = [];
        foreach ([$row->settled_on, $row->matched_settled_on, $row->manual_settled_on, $row->due_on] as $date) {
            if ($date !== null) {
                $months[] = CarbonImmutable::instance($date)->startOfMonth()->toDateString();
            }
        }

        PayrollInputsChanged::dispatch([(int) $row->personal_manager_id], 'invoice.manual', array_values(array_unique($months)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Request $request): array
    {
        $raw = (string) $request->query('month', '');
        $month = preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $raw) === 1
            ? CarbonImmutable::parse(substr($raw, 0, 7).'-01')->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();
        $managerId = $request->integer('manager') ?: null;

        return $this->queue->build($month, $managerId, max(1, $request->integer('page') ?: 1)) + [
            'query' => ['month' => $month->format('Y-m'), 'manager' => $managerId, 'page' => $request->integer('page') ?: 1],
            'can_edit' => $this->crmActor($request)->can('crm-motivation.edit'),
        ];
    }
}
