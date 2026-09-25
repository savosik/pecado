<?php

namespace App\Services\Assistant;

/**
 * Стоимость хода в долларах по usage ответа и прайсу config/assistant.php.
 *
 * Модель берётся из ответа, а не из запроса: при фолбэке отвечала другая.
 * Неизвестная модель считается по прайсу запрошенной, а если нет и её —
 * по самой дорогой строке прайса, чтобы не занизить счёт.
 */
final class CostCalculator
{
    /**
     * @param  array<string, mixed>  $usage
     */
    public function forUsage(?string $model, array $usage): float
    {
        $price = $this->price($model);

        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        $cacheWrite = (int) ($usage['cache_creation_input_tokens'] ?? 0);

        $usd = ($input * $price['input'] + $output * $price['output']
            + $cacheRead * $price['cache_read'] + $cacheWrite * $price['cache_write']) / 1_000_000;

        return round($usd, 6);
    }

    /**
     * @return array{input: float, output: float, cache_read: float, cache_write: float}
     */
    private function price(?string $model): array
    {
        $pricing = (array) config('assistant.pricing', []);

        foreach ([$model, (string) config('assistant.model')] as $candidate) {
            if ($candidate !== null && isset($pricing[$candidate])) {
                return $pricing[$candidate];
            }
        }

        $max = ['input' => 0.0, 'output' => 0.0, 'cache_read' => 0.0, 'cache_write' => 0.0];

        foreach ($pricing as $row) {
            if (($row['output'] ?? 0) > $max['output']) {
                $max = $row;
            }
        }

        return $max;
    }
}
