<?php

namespace App\Services\Payroll\Dto;

/**
 * Действующие параметры расчёта: схема с наложенными отклонениями менеджера.
 *
 * Помнит, откуда взялся каждый верхний ключ параметра (схема, постоянное
 * отклонение или месяц) — экран настроек подсвечивает переопределённое.
 */
final class EffectiveParams
{
    public const SOURCE_SCHEME = 'scheme';

    public const SOURCE_PERMANENT = 'permanent';

    public const SOURCE_MONTH = 'month';

    /**
     * @param  list<array{key: string, enabled: bool}>  $order  компоненты в порядке применения
     * @param  array<string, array<string, mixed>>  $byComponent  ключ компонента → параметры
     * @param  array<string, array<string, string>>  $sources  ключ компонента → параметр → источник
     */
    public function __construct(
        public readonly ?int $schemeId,
        public readonly array $order,
        public readonly array $byComponent,
        public readonly array $sources = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(string $componentKey): array
    {
        return $this->byComponent[$componentKey] ?? [];
    }

    public function enabled(string $componentKey): bool
    {
        foreach ($this->order as $entry) {
            if ($entry['key'] === $componentKey) {
                return (bool) $entry['enabled'];
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function enabledKeys(): array
    {
        return array_values(array_map(
            fn (array $entry): string => $entry['key'],
            array_filter($this->order, fn (array $entry): bool => (bool) $entry['enabled']),
        ));
    }

    /**
     * Копия с заменёнными параметрами компонента — для what-if.
     *
     * @param  array<string, mixed>  $params
     */
    public function withComponent(string $componentKey, array $params): self
    {
        $byComponent = $this->byComponent;
        $byComponent[$componentKey] = $params;

        return new self($this->schemeId, $this->order, $byComponent, $this->sources);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'scheme_id' => $this->schemeId,
            'order' => $this->order,
            'by_component' => $this->byComponent,
            'sources' => $this->sources,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $order = [];
        foreach ((array) ($data['order'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry['key'])) {
                $order[] = ['key' => (string) $entry['key'], 'enabled' => (bool) ($entry['enabled'] ?? true)];
            }
        }

        return new self(
            schemeId: isset($data['scheme_id']) ? (int) $data['scheme_id'] : null,
            order: $order,
            byComponent: (array) ($data['by_component'] ?? []),
            sources: (array) ($data['sources'] ?? []),
        );
    }
}
