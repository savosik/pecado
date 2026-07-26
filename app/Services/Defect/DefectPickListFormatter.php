<?php

namespace App\Services\Defect;

use App\Models\DefectType;
use Illuminate\Support\Collection;

/**
 * Формирует человекочитаемый список отбора некондиции для комментария складу.
 *
 * Кладовщики собирают заказ по печатному документу из 1С и в WMS заходят редко,
 * поэтому в комментарий заказа уценки (уходит в 1С как warehouse_comment)
 * дописываем конкретику по каждой позиции: артикул, id партии брака, id типов
 * дефектов + текст, количество.
 *
 * Строка на позицию:
 *   арт. {sku} — партия #{product_defect_id} — дефекты [{ids}]: {текст} — {N} шт.
 *
 * «Ид дефектов» в БД на партии не хранится (defect_description — свободный
 * текст, метки склеены через «; »), поэтому id резолвим по совпадению имён с
 * DefectType. Для меток без совпадения id опускается, остаётся только текст.
 */
class DefectPickListFormatter
{
    /** Заголовок блока в комментарии для склада. */
    public const HEADING = 'Некондиция — отобрать со склада брака:';

    /**
     * @param  Collection<int, \App\Models\CartItem|\App\Models\OrderItem>  $items
     */
    public function format(Collection $items): string
    {
        if ($items->isEmpty()) {
            return '';
        }

        $typeIdByName = $this->defectTypeIdMap();

        $lines = $items->map(function ($item) use ($typeIdByName): string {
            $sku = $item->product?->sku ?: '—';
            $defectId = $item->product_defect_id ?? '—';
            $quantity = (int) $item->quantity;

            $description = $this->resolveDescription($item);
            [$ids, $text] = $this->splitDefects($description, $typeIdByName);

            $defectsPart = $ids !== ''
                ? "дефекты [{$ids}]: {$text}"
                : "дефекты: {$text}";

            return "арт. {$sku} — партия #{$defectId} — {$defectsPart} — {$quantity} шт.";
        })->all();

        return self::HEADING."\n".implode("\n", $lines);
    }

    /**
     * Описание дефекта: снапшот на позиции заказа приоритетнее, иначе — из партии.
     */
    private function resolveDescription($item): string
    {
        $snapshot = $item->defect_description ?? null;

        return trim((string) ($snapshot ?: $item->productDefect?->defect_description ?? ''));
    }

    /**
     * Разбивает описание на метки (через «;»), резолвит id из DefectType.
     *
     * @param  array<string, int>  $typeIdByName
     * @return array{0: string, 1: string} [csv id-шников, csv текста]
     */
    private function splitDefects(string $description, array $typeIdByName): array
    {
        $labels = collect(explode(';', $description))
            ->map(fn (string $label) => trim($label))
            ->filter()
            ->values();

        if ($labels->isEmpty()) {
            return ['', '—'];
        }

        $ids = $labels
            ->map(fn (string $label) => $typeIdByName[mb_strtolower($label)] ?? null)
            ->filter()
            ->unique()
            ->values();

        return [$ids->implode(','), $labels->implode(', ')];
    }

    /**
     * @return array<string, int> имя типа (lowercase) → id
     */
    private function defectTypeIdMap(): array
    {
        return DefectType::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn (int $id, string $name) => [mb_strtolower(trim($name)) => $id])
            ->all();
    }
}
