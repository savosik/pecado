<?php

namespace App\Http\Controllers\Crm\Concerns;

use App\Models\Organization;

/**
 * Справочник наших юрлиц для фильтров финансовых разделов.
 *
 * Один список на все экраны: разъехавшийся порядок или разный состав в
 * соседних фильтрах читались бы как разные справочники.
 */
trait ListsOrganizations
{
    /**
     * @return list<array{id: int, name: string}>
     */
    private function organizationOptions(): array
    {
        if (! config('erp.organizations.enabled')) {
            return [];
        }

        // Внутренние юрлица («Реклама») из взаиморасчётов исключены на входе —
        // в фильтре денег такой пункт всегда пуст и только путает.
        return Organization::query()
            ->where('is_settlements_excluded', false)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Organization $organization): array => [
                'id' => (int) $organization->getKey(),
                'name' => (string) $organization->name,
            ])
            ->all();
    }
}
