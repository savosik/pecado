<?php

namespace App\Services\Payroll;

use App\Models\PayrollScheme;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Версии схемы: какая действует для месяца и как завести новую.
 *
 * Первая версия материализуется из config/payroll.php при первом обращении —
 * сидер не нужен ни на проде, ни в тестах.
 */
class PayrollSchemeRepository
{
    public function __construct(private readonly PayrollCatalog $catalog) {}

    /**
     * Последняя версия, начавшая действовать не позже месяца.
     *
     * Месяц раньше первой версии считается по ней же: зарплата «до схемы»
     * не бывает, а первая версия — единственный источник умолчаний.
     */
    public function forMonth(CarbonInterface $month, string $code = PayrollScheme::CODE_SALES): PayrollScheme
    {
        $first = CarbonImmutable::instance($month)->startOfMonth();

        $scheme = PayrollScheme::query()
            ->where('code', $code)
            ->whereDate('effective_from', '<=', $first->toDateString())
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();

        if ($scheme !== null) {
            return $scheme;
        }

        return PayrollScheme::query()
            ->where('code', $code)
            ->orderBy('effective_from')
            ->orderBy('version')
            ->first() ?? $this->ensureDefault($code);
    }

    /**
     * Версия 1 из конфига, если у кода ещё нет ни одной версии.
     */
    public function ensureDefault(string $code = PayrollScheme::CODE_SALES): PayrollScheme
    {
        $existing = PayrollScheme::query()->where('code', $code)->orderBy('version')->first();

        if ($existing !== null) {
            return $existing;
        }

        $default = (array) config('payroll.default_scheme', []);

        return PayrollScheme::query()->create([
            'code' => $code,
            'version' => 1,
            'title' => (string) ($default['title'] ?? 'Схема расчёта'),
            'effective_from' => (string) ($default['effective_from'] ?? '2026-01-01'),
            'components' => $this->normalizeComponents((array) ($default['components'] ?? [])),
            'author_id' => null,
            'comment' => 'Первая версия — из config/payroll.php',
        ]);
    }

    /**
     * Новая версия с месяца: старая строка не правится.
     *
     * @param  list<array{key: string, enabled?: bool, defaults?: array<string, mixed>}>  $components
     */
    public function createVersion(
        array $components,
        CarbonInterface $effectiveFrom,
        ?User $author,
        ?string $comment = null,
        ?string $title = null,
        string $code = PayrollScheme::CODE_SALES,
    ): PayrollScheme {
        // Первая версия — всегда из конфига: новая версия не должна занять её номер.
        $this->ensureDefault($code);

        $latest = PayrollScheme::query()->where('code', $code)->max('version');
        $version = ((int) $latest) + 1;

        return PayrollScheme::query()->create([
            'code' => $code,
            'version' => $version,
            'title' => $title ?? sprintf('Схема v%d', $version),
            'effective_from' => CarbonImmutable::instance($effectiveFrom)->startOfMonth()->toDateString(),
            'components' => $this->normalizeComponents($components),
            'author_id' => $author?->getKey(),
            'comment' => $comment,
        ]);
    }

    /**
     * @return list<PayrollScheme>
     */
    public function versions(string $code = PayrollScheme::CODE_SALES): array
    {
        return PayrollScheme::query()
            ->where('code', $code)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->get()
            ->all();
    }

    /**
     * Компоненты схемы, о которых знает каталог, в порядке схемы.
     *
     * @return list<array{key: string, enabled: bool, defaults: array<string, mixed>}>
     */
    public function orderedComponents(PayrollScheme $scheme): array
    {
        return array_values(array_filter(
            $scheme->orderedComponents(),
            fn (array $entry): bool => $this->catalog->exists($entry['key']),
        ));
    }

    /**
     * @param  list<mixed>  $components
     * @return list<array{key: string, enabled: bool, defaults: array<string, mixed>}>
     */
    private function normalizeComponents(array $components): array
    {
        $rows = [];

        foreach ($components as $entry) {
            if (! is_array($entry) || ! isset($entry['key']) || ! $this->catalog->exists((string) $entry['key'])) {
                continue;
            }

            $rows[] = [
                'key' => (string) $entry['key'],
                'enabled' => (bool) ($entry['enabled'] ?? true),
                'defaults' => is_array($entry['defaults'] ?? null) ? $entry['defaults'] : [],
            ];
        }

        return $rows;
    }
}
