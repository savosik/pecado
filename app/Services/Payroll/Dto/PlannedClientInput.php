<?php

namespace App\Services\Payroll\Dto;

/**
 * Плановый клиент менеджера: партнёр с назначенным планом на месяц и его факт отгрузок.
 */
final class PlannedClientInput
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly ?float $plan,
        public readonly float $fact,
    ) {}

    public function isActive(): bool
    {
        return $this->fact > 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'plan' => $this->plan,
            'fact' => $this->fact,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            plan: isset($data['plan']) ? (float) $data['plan'] : null,
            fact: (float) ($data['fact'] ?? 0),
        );
    }
}
