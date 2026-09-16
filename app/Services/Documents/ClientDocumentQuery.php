<?php

namespace App\Services\Documents;

use App\Enums\PrintedDocumentType;
use App\Models\Organization;
use App\Models\PrintedDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Выборка печатных форм клиента — общая для кабинета и API v1.
 *
 * Клиент видит только документы с перенесённым файлом (`stored()`): ссылка на
 * ненайденный файл выглядит как поломка сайта, а не как задержка обмена.
 * Фильтр по контрагентам пересекается со своими юрлицами: чужой id в запросе
 * не должен доходить до SQL как валидное условие.
 */
class ClientDocumentQuery
{
    public const SORT_FIELDS = ['date', 'number', 'type', 'id'];

    /**
     * @param  array<string, mixed>  $filters  search, type[], company_id[], organization_id[],
     *                                         date_from, date_to, order_id, shipment_id
     * @return Builder<PrintedDocument>
     */
    public function builder(User $user, array $filters = [], bool $applyTypeFilter = true): Builder
    {
        $query = PrintedDocument::query()
            ->visibleTo($user)
            ->stored()
            ->with(['company:id,name', 'organization:id,name,is_stub', 'order:id,number,erp_number', 'shipment:id,number,erp_number']);

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $search).'%';
            // Номер ищем и в нормализованном виде: клиент копирует «29УТ-002488»
            // из письма, а набирает «29УТ002488».
            $normalized = '%'.preg_replace('/[^\p{L}\p{N}]+/u', '', $search).'%';

            $query->where(function (Builder $inner) use ($like, $normalized): void {
                $inner->where('number', 'like', $like)
                    ->orWhere('title', 'like', $like)
                    ->orWhere('erp_type_name', 'like', $like)
                    ->orWhereRaw("REPLACE(REPLACE(REPLACE(number, '-', ''), ' ', ''), '/', '') LIKE ?", [$normalized]);
            });
        }

        $types = $this->list($filters['type'] ?? null);

        if ($applyTypeFilter && $types !== []) {
            $query->whereIn('type', array_values(array_intersect($types, PrintedDocumentType::values())));
        }

        $companyIds = $this->list($filters['company_id'] ?? null);

        if ($companyIds !== []) {
            $own = $user->companies()->pluck('id')->map(fn ($id) => (string) $id)->all();
            $query->whereIn('company_id', array_values(array_intersect($companyIds, $own)) ?: [0]);
        }

        $organizationIds = $this->list($filters['organization_id'] ?? null);

        if ($organizationIds !== []) {
            $query->whereIn('organization_id', $organizationIds);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (! empty($filters['order_id'])) {
            $query->where('order_id', (int) $filters['order_id']);
        }

        if (! empty($filters['shipment_id'])) {
            $query->where('shipment_id', (int) $filters['shipment_id']);
        }

        return $query;
    }

    /**
     * Сортировка с тай-брейком по id: дат без времени у документов много, и
     * без него порядок внутри одного дня скачет между страницами.
     *
     * @param  Builder<PrintedDocument>  $query
     */
    public function applySort(Builder $query, ?string $sortBy, ?string $sortOrder): void
    {
        $field = in_array($sortBy, self::SORT_FIELDS, true) ? $sortBy : 'date';
        $query->orderBy($field, $sortOrder === 'asc' ? 'asc' : 'desc')->orderByDesc('id');
    }

    /**
     * Счётчики по видам документов для чипов быстрых фильтров.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function typeCounts(User $user, array $filters = []): array
    {
        // select() сбрасывает колонки и сортировку подзапросов, reorder() снимает
        // orderBy — иначе MySQL в режиме ONLY_FULL_GROUP_BY отвергнет запрос,
        // хотя SQLite в тестах его пропустит.
        return $this->builder($user, $filters, applyTypeFilter: false)
            ->reorder()
            ->select('type')
            ->selectRaw('COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Организации, встречающиеся в документах именно этого клиента.
     *
     * @return list<array{value: string, label: string}>
     */
    public function organizationOptions(User $user): array
    {
        $ids = PrintedDocument::query()
            ->visibleTo($user)
            ->stored()
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id');

        if ($ids->isEmpty()) {
            return [];
        }

        return Organization::query()
            ->whereIn('id', $ids)
            ->where('is_stub', false)
            ->ordered()
            ->get(['id', 'name'])
            ->map(fn (Organization $organization) => [
                'value' => (string) $organization->id,
                'label' => $organization->name,
            ])
            ->all();
    }

    /**
     * Документ-основание печатной формы.
     *
     * @return array{label: string, url: string}|null
     */
    public function base(PrintedDocument $document): ?array
    {
        if ($document->shipment) {
            return [
                'label' => 'Отгрузка '.($document->shipment->erp_number ?: $document->shipment->number),
                'url' => route('cabinet.shipments.show', $document->shipment->id),
            ];
        }

        if ($document->order) {
            return [
                'label' => 'Заказ '.($document->order->erp_number ?: $document->order->number),
                'url' => route('cabinet.orders.show', $document->order->id),
            ];
        }

        return null;
    }

    /**
     * Скаляр или список → список непустых строк.
     *
     * @return list<string>
     */
    public function list(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        $values = array_map(static fn (mixed $v): string => trim((string) $v), is_array($input) ? $input : [$input]);

        return array_values(array_unique(array_filter($values, static fn (string $v): bool => $v !== '')));
    }
}
