<?php

namespace App\Services\Payments;

use App\Models\Currency;
use App\Models\Payment;
use App\Models\User;
use App\Services\Currency\CabinetAmountConverter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Платежи клиента: выборка с фильтрами и представление — общие для кабинета и API v1.
 *
 * Платежи внутренних юрлиц («Реклама») — не расчёты клиента и не показываются.
 * Суммы хранятся в валюте 1С, показываются в валюте кабинета — как у отгрузок,
 * иначе один документ показывал бы клиенту разные суммы в разных разделах.
 */
class ClientPaymentQuery
{
    public const DIRECTION_LABELS = [
        Payment::DIRECTION_IN => 'Поступление',
        Payment::DIRECTION_OUT => 'Возврат',
    ];

    public const SORTS = ['id', 'date', 'amount'];

    public function __construct(private readonly CabinetAmountConverter $amounts) {}

    /**
     * @param  array<string, mixed>  $filters  search, direction (скаляр|список), company_id, date_from, date_to, amount_from, amount_to
     * @return Builder<Payment>
     */
    public function builder(User $user, array $filters = []): Builder
    {
        $query = Payment::query()
            ->where('user_id', $user->id)
            ->withoutInternalOrganizations()
            ->with(['company', 'organization']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $inner->where('number', 'like', "%{$search}%")
                    ->orWhere('bank_number', 'like', "%{$search}%")
                    ->orWhere('uip', 'like', "%{$search}%")
                    ->orWhere('purpose', 'like', "%{$search}%");
            });
        }

        $directions = $this->directions($filters['direction'] ?? null);

        if ($directions !== []) {
            $query->whereIn('direction', $directions);
        }

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (! empty($filters['amount_from'])) {
            $query->where('amount', '>=', $filters['amount_from']);
        }

        if (! empty($filters['amount_to'])) {
            $query->where('amount', '<=', $filters['amount_to']);
        }

        return $query;
    }

    /**
     * Сортировка с тай-брейком по id: платежи одного дня иначе разъезжаются между страницами.
     *
     * @param  Builder<Payment>  $query
     */
    public function applySort(Builder $query, ?string $sortBy, ?string $sortOrder): void
    {
        if (in_array($sortBy, self::SORTS, true)) {
            $query->orderBy($sortBy, $sortOrder === 'asc' ? 'asc' : 'desc');
        }

        $query->orderBy('id', 'desc');
    }

    /**
     * Мультивыбор, понимающий и скаляр.
     *
     * @return list<string>
     */
    public function directions(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        $values = array_map('strval', is_array($input) ? $input : [$input]);

        return array_values(array_intersect(array_unique($values), array_keys(self::DIRECTION_LABELS)));
    }

    /**
     * @return array<string, mixed>
     */
    public function row(Payment $payment, ?Currency $currency, bool $iso = false): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'date' => $payment->date->format('Y-m-d'),
            'date_label' => $payment->date->format('d.m.Y'),
            'direction' => $payment->direction,
            'direction_label' => self::DIRECTION_LABELS[$payment->direction] ?? $payment->direction,
            'bank_number' => $payment->bank_number,
            'bank_confirmed' => (bool) $payment->bank_confirmed,
            'amount' => (float) $payment->amount,
            'amount_converted' => $this->amounts->convert((float) $payment->amount, $payment->currency_code, $currency),
            'currency_code' => $payment->currency_code,
            'company_name' => $payment->company?->name,
        ] + ($iso ? ['company_id' => $payment->company_id] : []);
    }

    /**
     * @return array<string, mixed>
     */
    public function card(Payment $payment, ?Currency $currency, bool $iso = false): array
    {
        $payment->loadMissing(['company', 'organization:id,name,legal_name,tax_id,is_stub']);

        return array_merge($this->row($payment, $currency, $iso), [
            'document_type' => $payment->document_type,
            'operation_name' => $payment->operation_name,
            'bank_date' => $payment->bank_date instanceof \Illuminate\Support\Carbon
                ? ($iso ? $payment->bank_date->toDateString() : $payment->bank_date->format('d.m.Y'))
                : null,
            'bank_confirmed_at' => $iso ? $payment->bank_confirmed_at?->toIso8601String() : $payment->bank_confirmed_at?->format('d.m.Y H:i'),
            'organization_account' => $payment->organization_account,
            'organization_bank_name' => $payment->organization_bank_name,
            'payer_account' => $payment->payer_account,
            'payer_bank_name' => $payment->payer_bank_name,
            'uip' => $payment->uip,
            'purpose' => $payment->purpose,
            'company' => $payment->company ? [
                'id' => $payment->company->id,
                'name' => $payment->company->name,
                'legal_name' => $payment->company->legal_name,
                'tax_id' => $payment->company->tax_id,
            ] : null,
            'seller' => $this->seller($payment),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function seller(Payment $payment): ?array
    {
        if (! config('erp.organizations.enabled') || $payment->organization === null) {
            return null;
        }

        return [
            'name' => $payment->organization->name,
            'legal_name' => $payment->organization->legal_name,
            'tax_id' => $payment->organization->tax_id,
            'is_stub' => (bool) $payment->organization->is_stub,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function directionOptions(): array
    {
        return array_map(
            fn ($value, $label) => ['value' => $value, 'label' => $label],
            array_keys(self::DIRECTION_LABELS),
            self::DIRECTION_LABELS,
        );
    }
}
