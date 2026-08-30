<?php

namespace App\Services\Payroll\Dto;

/**
 * Входы расчёта зарплаты за месяц — всё, что калькулятору нужно знать о мире.
 *
 * Неизменяемый объект: калькулятор чистый, а прогноз и советы получают
 * гипотетические входы через {@see with()} и считают той же формулой.
 * Сериализуется целиком в снимок (`payroll_calculations.inputs`), чтобы
 * утверждённый расчёт читался без обращения к живым данным.
 */
final class PayrollInputs
{
    /**
     * @param  string  $month  первое число месяца, Y-m-d
     * @param  list<PlannedClientInput>  $plannedClients  партнёры с планом на месяц и их факт
     * @param  list<InvoiceInput>  $invoices  накладные месяца с задержкой хотя бы в день
     *                                        (закрытые день в день не хранятся — их только считают)
     * @param  list<InvoiceInput>  $atRiskInvoices  неоплаченные накладные со сроком не позже конца месяца
     * @param  list<AdjustmentInput>  $extraItems  позиции доп. дохода
     * @param  list<AdjustmentInput>  $corrections  корректировки РОПа
     * @param  list<array<string, mixed>>  $newClients  новые клиенты (волна 2)
     * @param  array{total: int, passed: int, left: int}  $workingDays
     */
    public function __construct(
        public readonly int $managerId,
        public readonly string $month,
        public readonly ?float $plan,
        public readonly float $revenue,
        public readonly array $plannedClients = [],
        public readonly array $invoices = [],
        public readonly array $atRiskInvoices = [],
        public readonly int $settledOnTimeCount = 0,
        public readonly array $extraItems = [],
        public readonly array $corrections = [],
        public readonly array $newClients = [],
        public readonly array $workingDays = ['total' => 0, 'passed' => 0, 'left' => 0],
        public readonly ?string $collectedAt = null,
    ) {}

    /**
     * Плановые клиенты с отгрузкой в месяце.
     *
     * @return list<PlannedClientInput>
     */
    public function activeClients(): array
    {
        return array_values(array_filter(
            $this->plannedClients,
            fn (PlannedClientInput $client): bool => $client->isActive(),
        ));
    }

    /**
     * Копия с изменёнными полями (ключи как в toArray()).
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        return self::fromArray(array_replace($this->toArray(), $changes));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'manager_id' => $this->managerId,
            'month' => $this->month,
            'plan' => $this->plan,
            'revenue' => $this->revenue,
            'planned_clients' => array_map(fn (PlannedClientInput $c): array => $c->toArray(), $this->plannedClients),
            'invoices' => array_map(fn (InvoiceInput $i): array => $i->toArray(), $this->invoices),
            'at_risk_invoices' => array_map(fn (InvoiceInput $i): array => $i->toArray(), $this->atRiskInvoices),
            'settled_on_time_count' => $this->settledOnTimeCount,
            'extra_items' => array_map(fn (AdjustmentInput $a): array => $a->toArray(), $this->extraItems),
            'corrections' => array_map(fn (AdjustmentInput $a): array => $a->toArray(), $this->corrections),
            'new_clients' => $this->newClients,
            'working_days' => $this->workingDays,
            'collected_at' => $this->collectedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $list = static fn (mixed $rows, callable $map): array => array_values(array_map(
            $map,
            array_filter(is_array($rows) ? $rows : [], 'is_array'),
        ));

        $workingDays = is_array($data['working_days'] ?? null) ? $data['working_days'] : [];

        return new self(
            managerId: (int) ($data['manager_id'] ?? 0),
            month: (string) ($data['month'] ?? ''),
            plan: isset($data['plan']) ? (float) $data['plan'] : null,
            revenue: (float) ($data['revenue'] ?? 0),
            plannedClients: $list($data['planned_clients'] ?? [], fn (array $row) => PlannedClientInput::fromArray($row)),
            invoices: $list($data['invoices'] ?? [], fn (array $row) => InvoiceInput::fromArray($row)),
            atRiskInvoices: $list($data['at_risk_invoices'] ?? [], fn (array $row) => InvoiceInput::fromArray($row)),
            settledOnTimeCount: (int) ($data['settled_on_time_count'] ?? 0),
            extraItems: $list($data['extra_items'] ?? [], fn (array $row) => AdjustmentInput::fromArray($row)),
            corrections: $list($data['corrections'] ?? [], fn (array $row) => AdjustmentInput::fromArray($row)),
            newClients: $list($data['new_clients'] ?? [], fn (array $row) => $row),
            workingDays: [
                'total' => (int) ($workingDays['total'] ?? 0),
                'passed' => (int) ($workingDays['passed'] ?? 0),
                'left' => (int) ($workingDays['left'] ?? 0),
            ],
            collectedAt: isset($data['collected_at']) ? (string) $data['collected_at'] : null,
        );
    }

    /**
     * Отпечаток входов без времени сбора: не изменился — черновик не переписываем.
     */
    public function hash(): string
    {
        $data = $this->toArray();
        unset($data['collected_at']);

        return hash('sha256', (string) json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
