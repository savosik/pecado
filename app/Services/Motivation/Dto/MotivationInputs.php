<?php

namespace App\Services\Motivation\Dto;

/**
 * Входы переменной части по Положению редакции 2.2 (эпик mot-00).
 *
 * Отдельный объект, а не россыпь полей в {@see \App\Services\Payroll\Dto\PayrollInputs}:
 * набор величин здесь свой, и действующая схема sal-00 о нём знать не должна.
 * В снимок расчёта попадает целиком — утверждённый месяц читается без обращения
 * к живым данным.
 *
 * Три группы отгрузок разложены заранее (раздел 03 ТЗ): база и Новые партнёры
 * взаимоисключающи (п. 6.3.3), Фокус-перечень пересекается с обеими и учитывается
 * дополнительно (п. 6.4.2). Возвраты периода уже вычтены из каждой группы
 * (пп. 2.12, 6.2.1, 6.3.2, 6.4.1) и сохранены отдельно как улика.
 *
 * База начисления К1 — не остаток долга на конец периода, а интеграл:
 * сумма произведений «остаток × календарных дней просрочки в периоде», ₽·дней.
 * Остаток на конец периода наказывал бы за долги, которые работник как раз собрал.
 */
final class MotivationInputs
{
    /**
     * @param  float  $baseRevenue  отгрузки Закреплённой базы за вычетом возвратов, ₽
     * @param  float  $newPartnersRevenue  отгрузки партнёрам в Периоде новизны, ₽
     * @param  int  $newPartnersCount  сколько таких партнёров в периоде
     * @param  float  $focusRevenue  отгрузки товаров Фокус-перечня, ₽
     * @param  float  $overdueIntegral  Σ (остаток просроченной задолженности × дни просрочки), ₽·дней
     * @param  int  $workingDaysTotal  рабочих дней в периоде по производственному календарю
     * @param  int  $workedDays  из них отработано (п. 10.1); равно total, если отсутствий не было
     * @param  int  $substitutionDays  рабочих дней замещения отсутствующего работника (п. 4.3)
     * @param  float|null  $guaranteeBase  средняя оплата труда за три периода до введения Положения (п. 12.3)
     * @param  list<array<string, mixed>>  $baseRows  улики П1: партнёры и их суммы
     * @param  list<array<string, mixed>>  $newRows  улики П2: партнёры в Периоде новизны
     * @param  list<array<string, mixed>>  $focusRows  улики П3: позиции; ключ `rate` задаёт повышенную ставку (п. 6.4.4)
     * @param  list<array<string, mixed>>  $overdueRows  улики К1: документы, остаток и дни просрочки
     * @param  array{base: float, new: float, focus: float}  $returns  возвраты периода по группам, ₽
     */
    public function __construct(
        public readonly float $baseRevenue = 0.0,
        public readonly float $newPartnersRevenue = 0.0,
        public readonly int $newPartnersCount = 0,
        public readonly float $focusRevenue = 0.0,
        public readonly float $overdueIntegral = 0.0,
        public readonly int $workingDaysTotal = 0,
        public readonly int $workedDays = 0,
        public readonly int $substitutionDays = 0,
        public readonly ?float $guaranteeBase = null,
        public readonly array $baseRows = [],
        public readonly array $newRows = [],
        public readonly array $focusRows = [],
        public readonly array $overdueRows = [],
        public readonly array $returns = ['base' => 0.0, 'new' => 0.0, 'focus' => 0.0],
    ) {}

    /**
     * Были ли у работника дни отсутствия в периоде.
     */
    public function hasAbsence(): bool
    {
        return $this->workingDaysTotal > 0 && $this->workedDays < $this->workingDaysTotal;
    }

    /**
     * Личный план после уменьшения на дни отсутствия (п. 10.1).
     *
     * Уменьшение предшествует вычислению Порога оплаты — иначе система наказывала бы
     * за законный отпуск. Без данных табеля план возвращается без изменений.
     */
    public function reducedPlan(?float $plan): ?float
    {
        if ($plan === null || ! $this->hasAbsence()) {
            return $plan;
        }

        return $plan * $this->workedDays / $this->workingDaysTotal;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'base_revenue' => $this->baseRevenue,
            'new_partners_revenue' => $this->newPartnersRevenue,
            'new_partners_count' => $this->newPartnersCount,
            'focus_revenue' => $this->focusRevenue,
            'overdue_integral' => $this->overdueIntegral,
            'working_days_total' => $this->workingDaysTotal,
            'worked_days' => $this->workedDays,
            'substitution_days' => $this->substitutionDays,
            'guarantee_base' => $this->guaranteeBase,
            'base_rows' => $this->baseRows,
            'new_rows' => $this->newRows,
            'focus_rows' => $this->focusRows,
            'overdue_rows' => $this->overdueRows,
            'returns' => $this->returns,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rows = static fn (mixed $value): array => array_values(array_filter(
            is_array($value) ? $value : [],
            'is_array',
        ));

        $returns = is_array($data['returns'] ?? null) ? $data['returns'] : [];

        return new self(
            baseRevenue: (float) ($data['base_revenue'] ?? 0),
            newPartnersRevenue: (float) ($data['new_partners_revenue'] ?? 0),
            newPartnersCount: (int) ($data['new_partners_count'] ?? 0),
            focusRevenue: (float) ($data['focus_revenue'] ?? 0),
            overdueIntegral: (float) ($data['overdue_integral'] ?? 0),
            workingDaysTotal: (int) ($data['working_days_total'] ?? 0),
            workedDays: (int) ($data['worked_days'] ?? 0),
            substitutionDays: (int) ($data['substitution_days'] ?? 0),
            guaranteeBase: isset($data['guarantee_base']) ? (float) $data['guarantee_base'] : null,
            baseRows: $rows($data['base_rows'] ?? []),
            newRows: $rows($data['new_rows'] ?? []),
            focusRows: $rows($data['focus_rows'] ?? []),
            overdueRows: $rows($data['overdue_rows'] ?? []),
            returns: [
                'base' => (float) ($returns['base'] ?? 0),
                'new' => (float) ($returns['new'] ?? 0),
                'focus' => (float) ($returns['focus'] ?? 0),
            ],
        );
    }
}
