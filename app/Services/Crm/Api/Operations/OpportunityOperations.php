<?php

namespace App\Services\Crm\Api\Operations;

use App\Enums\Crm\OpportunityPreset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\User;
use App\Services\Crm\Api\OperationInput;
use App\Services\Crm\OpportunityService;
use App\Services\Crm\PlanScopeResolver;

/**
 * Возможности: ранжированный список «кому звонить и почему».
 *
 * Ровно тот же `OpportunityService`, что и на экране: веса сигналов лежат
 * в конфиге, и агент получает тот же порядок обзвона, что видит менеджер.
 * Каждая строка несёт объяснение — списку без причины агент не поверит
 * ровно так же, как человек.
 */
class OpportunityOperations
{
    public function __construct(
        private readonly OpportunityService $opportunities,
        private readonly PlanScopeResolver $scopes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function list(User $actor, OperationInput $input): array
    {
        $month = $input->month();
        $scope = $this->scopes->resolve($actor, $input->string('scope'), $input->int('scope_id'));
        $preset = OpportunityPreset::tryFrom((string) $input->string('preset')) ?? OpportunityPreset::PLAN_LAG;

        $result = $this->opportunities->rank(
            $month,
            $scope,
            $preset,
            $this->dimension($input),
            $input->int('limit'),
        );

        return $result + [
            'month' => $month->format('Y-m'),
            'preset' => $preset->value,
            'scope' => $this->scopes->payload($scope),
        ];
    }

    /**
     * Измерение пресета «не берут X». Название берётся из справочника, а не из
     * аргумента: в объяснение строки должно попасть имя из базы, а не текст,
     * который прислал вызывающий.
     *
     * @return array{dimension: string|null, value: int|null, label: string|null}
     */
    private function dimension(OperationInput $input): array
    {
        $dimension = (string) $input->string('dimension', '');
        $value = (int) ($input->int('value') ?? 0);

        if (! in_array($dimension, ['brand', 'category'], true) || $value <= 0) {
            return ['dimension' => null, 'value' => null, 'label' => null];
        }

        $name = $dimension === 'brand'
            ? Brand::query()->whereKey($value)->value('name')
            : Category::query()->whereKey($value)->value('name');

        if ($name === null) {
            return ['dimension' => null, 'value' => null, 'label' => null];
        }

        return [
            'dimension' => $dimension,
            'value' => $value,
            'label' => ($dimension === 'brand' ? 'бренд' : 'категория').' «'.$name.'»',
        ];
    }
}
