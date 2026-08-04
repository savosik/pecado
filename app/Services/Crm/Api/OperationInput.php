<?php

namespace App\Services\Crm\Api;

use Carbon\CarbonImmutable;

/**
 * Проверенные аргументы операции.
 *
 * Обработчики принимают этот объект, а не Illuminate\Http\Request: одна и та же
 * операция вызывается из REST и из MCP, и обработчик, зависящий от HTTP-запроса,
 * работал бы только в одном из двух каналов.
 */
final class OperationInput
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function __construct(private readonly array $args) {}

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->args) && $this->args[$key] !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->args[$key] ?? $default;
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $value = $this->args[$key] ?? $default;

        return $value === null ? null : (string) $value;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $value = $this->args[$key] ?? $default;

        return $value === null ? null : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->args[$key] ?? $default, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function array(string $key): array
    {
        $value = $this->args[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * Месяц операции. Пусто — текущий: агент чаще спрашивает «как сейчас»,
     * и требовать месяц в каждом вызове значило бы платить лишним аргументом.
     */
    public function month(string $key = 'month'): CarbonImmutable
    {
        $value = $this->string($key);

        if ($value === null || ! preg_match('/^\d{4}-\d{2}$/', $value)) {
            return CarbonImmutable::now()->startOfMonth();
        }

        return CarbonImmutable::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->args;
    }

    /**
     * Только перечисленные ключи и только те, что реально пришли — для передачи
     * в сервисы, которые различают «поле не трогали» и «поле очистили».
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->args, array_flip($keys));
    }
}
