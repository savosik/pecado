<?php

namespace App\Http\Controllers\Crm;

use App\Events\Payroll\PayrollInputsChanged;
use App\Models\PayrollInvoiceSettlement;
use App\Models\PersonalManager;
use App\Services\Payroll\Invoices\PayrollInvoiceSettlementProjector;
use App\Services\Payroll\Support\MonthLabel;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ручная разметка накладных для зарплаты (РОП).
 *
 * Очередь `needs_review` — оплачено по 1С, но дата закрытия не восстановлена:
 * взаимозачёт, платёж без номера, аванс на другой заказ. РОП проставляет дату
 * с основанием — как сейчас размечает просрочку в Excel; проектор ручную дату
 * не затирает, а снять её можно только руками.
 */
class SalaryInvoiceController extends CrmController
{
    private const PAGE = 100;

    public function __construct(private readonly PayrollInvoiceSettlementProjector $projector) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manager' => ['nullable', 'integer', 'min:1'],
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'mode' => ['nullable', 'string', 'in:review,manual,penalized'],
        ], [
            'month.regex' => 'Месяц должен быть в формате ГГГГ-ММ.',
            'mode.in' => 'Неизвестный режим списка.',
        ]);

        $mode = (string) ($data['mode'] ?? 'review');
        $query = PayrollInvoiceSettlement::query()
            ->with(['user:id,name,erp_name', 'manualBy:id,name'])
            ->orderByDesc('shipped_on')
            ->orderByDesc('id');

        if (! empty($data['manager'])) {
            $query->forManager((int) $data['manager']);
        }

        match ($mode) {
            'manual' => $query->whereNotNull('manual_settled_on'),
            'penalized' => $query->whereNotNull('delay_working_days')->where('delay_working_days', '>=', (int) config('payroll.invoices.grace_working_days', 2) + 1),
            default => $query->where('needs_review', true),
        };

        if (! empty($data['month'])) {
            $month = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01')->startOfMonth();
            $column = $mode === 'review' ? 'shipped_on' : 'settled_on';
            $query->whereDate($column, '>=', $month->toDateString())
                ->whereDate($column, '<=', $month->endOfMonth()->toDateString());
        }

        $total = (clone $query)->count();
        $rows = $query->limit(self::PAGE)->get()->map(fn (PayrollInvoiceSettlement $row): array => $this->row($row))->all();

        return response()->json([
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
            'managers' => PersonalManager::query()->active()->orderBy('name')->get(['id', 'name'])->map(fn (PersonalManager $m): array => ['id' => (int) $m->getKey(), 'name' => (string) $m->name])->all(),
        ]);
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

        return response()->json(['saved' => true, 'invoice' => $this->row($row->load(['user:id,name,erp_name', 'manualBy:id,name']))]);
    }

    public function unmark(PayrollInvoiceSettlement $invoice): JsonResponse
    {
        $row = $this->projector->clearManual($invoice);

        $this->notify($row);

        return response()->json(['saved' => true, 'invoice' => $this->row($row->load(['user:id,name,erp_name', 'manualBy:id,name']))]);
    }

    private function notify(PayrollInvoiceSettlement $row): void
    {
        if ($row->personal_manager_id === null) {
            return;
        }

        $months = [];
        foreach ([$row->settled_on, $row->matched_settled_on, $row->manual_settled_on] as $date) {
            if ($date !== null) {
                $months[] = CarbonImmutable::instance($date)->startOfMonth()->toDateString();
            }
        }

        PayrollInputsChanged::dispatch([(int) $row->personal_manager_id], 'invoice.manual', array_values(array_unique($months)));
    }

    /**
     * @return array<string, mixed>
     */
    private function row(PayrollInvoiceSettlement $row): array
    {
        return [
            'id' => (int) $row->getKey(),
            'shipment_id' => (int) $row->shipment_id,
            'erp_number' => $row->erp_number,
            'partner_id' => $row->user_id,
            'partner_name' => $row->user === null ? '' : (string) $row->user->display_name,
            'manager_id' => $row->personal_manager_id,
            'amount' => (float) $row->total_amount,
            'shipped_on' => $row->shipped_on?->toDateString(),
            'shipped_label' => $row->shipped_on === null ? null : MonthLabel::day($row->shipped_on),
            'due_on' => $row->due_on?->toDateString(),
            'due_source' => $row->due_source,
            'payment_status' => $row->payment_status,
            'matched_settled_on' => $row->matched_settled_on?->toDateString(),
            'matched_paid_amount' => (float) $row->matched_paid_amount,
            'payments' => $row->payments ?? [],
            'manual_settled_on' => $row->manual_settled_on?->toDateString(),
            'manual_comment' => $row->manual_comment,
            'manual_by' => $row->manualBy === null ? null : (string) $row->manualBy->name,
            'settled_on' => $row->settled_on?->toDateString(),
            'settled_source' => $row->settled_source,
            'delay_working_days' => $row->delay_working_days,
            'delay_calendar_days' => $row->delay_calendar_days,
            'needs_review' => (bool) $row->needs_review,
        ];
    }
}
