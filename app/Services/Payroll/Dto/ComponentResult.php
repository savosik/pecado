<?php

namespace App\Services\Payroll\Dto;

use App\Enums\Payroll\ComponentKind;

/**
 * Результат компонента: число, пояснение с подставленными числами и улики.
 *
 * `amount` — рубли в итог (у kind = amount); `value` — число фактора (выручка,
 * штраф, множитель); `effectRub` — сколько рублей этот фактор прибавил или отнял
 * у премии (what-if без него), для водопада и советов.
 */
final class ComponentResult
{
    /**
     * @param  array<string, mixed>|list<mixed>  $evidence
     * @param  list<ComponentResult>  $children
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $meta  служебные числа для экрана (доля, ступень, план/факт)
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ComponentKind $kind,
        public readonly ?float $amount = null,
        public readonly ?float $value = null,
        public readonly string $explanation = '',
        public readonly array $evidence = [],
        public readonly array $children = [],
        public readonly array $warnings = [],
        public readonly ?float $effectRub = null,
        public readonly array $meta = [],
    ) {}

    public function withEffect(?float $effectRub): self
    {
        return new self(
            $this->key, $this->label, $this->kind, $this->amount, $this->value,
            $this->explanation, $this->evidence, $this->children, $this->warnings,
            $effectRub, $this->meta,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'kind' => $this->kind->value,
            'amount' => $this->amount,
            'value' => $this->value,
            'explanation' => $this->explanation,
            'evidence' => $this->evidence,
            'children' => array_map(fn (ComponentResult $child): array => $child->toArray(), $this->children),
            'warnings' => $this->warnings,
            'effect_rub' => $this->effectRub,
            'meta' => $this->meta,
        ];
    }
}
