<?php

namespace App\Http\Controllers\Admin;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

/**
 * Платежи в админке.
 *
 * Мастер данных — 1С, поэтому создания и редактирования реквизитов здесь нет
 * и не должно быть: следующий `payment.updated` всё равно перезапишет их,
 * а расхождение с учётной системой обнаружится не сразу. Редактируется только
 * локальный комментарий; удаление — мягкое, с возможностью восстановления.
 */
class PaymentController extends Controller
{
    private const DIRECTION_LABELS = [
        Payment::DIRECTION_IN => 'Поступление',
        Payment::DIRECTION_OUT => 'Возврат клиенту',
    ];

    private const ALLOCATION_LABELS = [
        Payment::ALLOCATION_ALLOCATED => 'Разнесён',
        Payment::ALLOCATION_PARTIAL => 'Разнесён частично',
        Payment::ALLOCATION_ADVANCE => 'Аванс',
    ];

    private const SORTS = ['id', 'date', 'number', 'amount', 'unallocated_amount', 'created_at'];

    public function index(Request $request)
    {
        $onlyTrashed = $request->boolean('trashed');

        $query = $onlyTrashed
            ? Payment::onlyTrashed()->with(['user', 'company', 'organization'])
            : Payment::query()->with(['user', 'company', 'organization']);

        $query->withCount('allocations');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('bank_number', 'like', "%{$search}%")
                    ->orWhere('uip', 'like', "%{$search}%")
                    ->orWhere('tax_id', 'like', "%{$search}%")
                    ->orWhereHas('company', function ($cq) use ($search) {
                        $cq->withoutGlobalScopes()
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('legal_name', 'like', "%{$search}%");
                    });
            });
        }

        if ($direction = $request->input('direction')) {
            $query->where('direction', $direction);
        }

        // Состояние разнесения считается по нераспределённому остатку: отдельной
        // колонки нет, и заводить её ради фильтра значило бы держать в синхроне
        // ещё одно производное поле.
        match ($request->input('allocation_status')) {
            Payment::ALLOCATION_ALLOCATED => $query->where('unallocated_amount', '<=', Payment::EPSILON),
            Payment::ALLOCATION_PARTIAL => $query->where('unallocated_amount', '>', Payment::EPSILON)
                ->where('allocated_amount', '>', Payment::EPSILON),
            Payment::ALLOCATION_ADVANCE => $query->where('allocated_amount', '<=', Payment::EPSILON)
                ->where('unallocated_amount', '>', Payment::EPSILON),
            default => null,
        };

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->input('user_id'));
        }

        $organizationId = $request->input('organization_id');
        if ($organizationId === 'none') {
            $query->whereNull('organization_id');
        } elseif ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        if ($request->filled('bank_confirmed')) {
            $query->where('bank_confirmed', $request->boolean('bank_confirmed'));
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('date', '<=', $dateTo);
        }

        if ($request->filled('amount_from')) {
            $query->where('amount', '>=', (float) $request->input('amount_from'));
        }
        if ($request->filled('amount_to')) {
            $query->where('amount', '<=', (float) $request->input('amount_to'));
        }

        if ($currency = $request->input('currency_code')) {
            $query->where('currency_code', $currency);
        }

        $sortBy = $request->input('sort_by', 'date');
        $sortOrder = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';

        if (in_array($sortBy, self::SORTS, true)) {
            $query->orderBy($sortBy, $sortOrder);
        }
        // Вторичная сортировка: платежи одного дня иначе разъезжаются между страницами.
        $query->orderBy('id', 'desc');

        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);
        $payments = $query->paginate($perPage)->withQueryString();

        $payments->getCollection()->transform(fn (Payment $payment) => $this->listRow($payment));

        return Inertia::render('Admin/Pages/Payments/Index', [
            'payments' => $payments,
            'filters' => array_merge(
                $request->only([
                    'search', 'direction', 'allocation_status', 'user_id', 'organization_id',
                    'bank_confirmed', 'date_from', 'date_to', 'amount_from', 'amount_to',
                    'currency_code', 'sort_by', 'sort_order', 'per_page',
                ]),
                ['trashed' => $onlyTrashed]
            ),
            'trashedCount' => Payment::onlyTrashed()->count(),
            'directions' => $this->options(self::DIRECTION_LABELS),
            'allocationStatuses' => $this->options(self::ALLOCATION_LABELS),
            'organizations' => \App\Models\Organization::query()->ordered()->get(['id', 'name', 'is_stub']),
            'organizationsEnabled' => config('erp.organizations.enabled'),
        ]);
    }

    public function show(Payment $payment)
    {
        $payment->load(['user', 'company', 'organization', 'allocations.shipment', 'allocations.order']);

        return Inertia::render('Admin/Pages/Payments/Show', [
            'payment' => array_merge($this->listRow($payment), [
                'operation_name' => $payment->operation_name,
                'operation_code' => $payment->operation_code,
                'document_type' => $payment->document_type,
                'contractor_uuid' => $payment->contractor_uuid,
                'tax_id' => $payment->tax_id,
                'organization_account' => $payment->organization_account,
                'organization_bank_name' => $payment->organization_bank_name,
                'payer_account' => $payment->payer_account,
                'payer_bank_name' => $payment->payer_bank_name,
                'bank_date' => $payment->bank_date instanceof \Illuminate\Support\Carbon ? $payment->bank_date->format('d.m.Y') : null,
                'bank_confirmed_at' => $payment->bank_confirmed_at?->format('d.m.Y H:i'),
                'uip' => $payment->uip,
                'purpose' => $payment->purpose,
                'comment' => $payment->comment,
                'erp_created_at' => $payment->erp_created_at?->format('d.m.Y H:i'),
                'erp_updated_at' => $payment->erp_updated_at?->format('d.m.Y H:i'),
                'allocations' => $payment->allocations
                    ->sortBy(fn ($allocation) => $allocation->line_number ?? $allocation->id)
                    ->values()
                    ->map(fn ($allocation) => [
                        'id' => $allocation->id,
                        'line_number' => $allocation->line_number,
                        'amount' => (float) $allocation->amount,
                        'shipment_uuid' => $allocation->shipment_uuid,
                        'order_uuid' => $allocation->order_uuid,
                        'order_number' => $allocation->order?->number,
                        'shipment' => $allocation->shipment ? [
                            'id' => $allocation->shipment->id,
                            'number' => $allocation->shipment->number ?? $allocation->shipment->erp_number,
                            'date' => $allocation->shipment->date?->format('d.m.Y'),
                            'total_amount' => (float) $allocation->shipment->total_amount,
                            'paid_amount' => (float) $allocation->shipment->paid_amount,
                            'payment_status' => $allocation->shipment->payment_status,
                            'payment_status_label' => $allocation->shipment->payment_status_label,
                        ] : null,
                    ]),
            ]),
            'organizationsEnabled' => config('erp.organizations.enabled'),
        ]);
    }

    /**
     * Комментарий — единственное поле платежа, которое ведёт сайт.
     * В 1С не уходит и из 1С не перезаписывается.
     */
    public function updateComment(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ], [
            'comment.max' => 'Комментарий не должен быть длиннее 2000 символов.',
        ]);

        $payment->update(['comment' => $validated['comment'] ?? null]);

        return back()->with('success', 'Комментарий сохранён');
    }

    public function destroy(Payment $payment)
    {
        $payment->delete();

        return redirect()->route('admin.payments.index')->with('success', 'Платёж удалён');
    }

    public function restore(int $id)
    {
        Payment::onlyTrashed()->findOrFail($id)->restore();

        return redirect()->route('admin.payments.index', ['trashed' => 1])->with('success', 'Платёж восстановлен');
    }

    public function forceDestroy(int $id)
    {
        Payment::onlyTrashed()->findOrFail($id)->forceDelete();

        return redirect()->route('admin.payments.index', ['trashed' => 1])->with('success', 'Платёж окончательно удалён');
    }

    /**
     * @return array<string, mixed>
     */
    private function listRow(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'uuid' => $payment->uuid,
            'number' => $payment->number,
            'date' => $payment->date->format('d.m.Y H:i'),
            'direction' => $payment->direction,
            'direction_label' => $payment->direction_label,
            'bank_number' => $payment->bank_number,
            'bank_confirmed' => (bool) $payment->bank_confirmed,
            'amount' => (float) $payment->amount,
            'currency_code' => $payment->currency_code,
            'allocated_amount' => (float) $payment->allocated_amount,
            'unallocated_amount' => (float) $payment->unallocated_amount,
            'allocation_status' => $payment->allocation_status,
            'allocation_status_label' => $payment->allocation_status_label,
            'allocations_count' => $payment->allocations_count ?? $payment->allocations()->count(),
            'created_at' => $payment->created_at->format('d.m.Y H:i'),
            'deleted_at' => $payment->deleted_at?->format('d.m.Y H:i'),
            'user' => $payment->user ? [
                'id' => $payment->user->id,
                'name' => $payment->user->erp_name ?: $payment->user->name,
                'email' => $payment->user->email,
            ] : null,
            'company' => $payment->company ? [
                'id' => $payment->company->id,
                'name' => $payment->company->name,
            ] : null,
            'organization' => $payment->organization ? [
                'id' => $payment->organization->id,
                'name' => $payment->organization->name,
                'is_stub' => $payment->organization->is_stub,
            ] : null,
        ];
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private function options(array $labels): array
    {
        return array_map(
            fn ($value, $label) => ['value' => $value, 'label' => $label],
            array_keys($labels),
            $labels
        );
    }
}
