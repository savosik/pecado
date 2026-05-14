<?php

namespace App\Services\ProductExport\Fields;

use App\Models\Product;
use App\Models\User;
use App\Services\ProductExport\ExportField;

/**
 * Поле-заглушка «Пустая колонка» (виртуальное).
 *
 * Ключ — `placeholder.{slug}`. Всегда возвращает пустую строку. Резолвится
 * FieldRegistry'ем для любого slug без явной регистрации.
 *
 * Why: при копировании структуры внешней выгрузки часть колонок не имеет аналога
 * в нашей системе (числовые коды бренда/категории, склады других регионов и
 * т.п.). Чтобы клиент мог просто сменить эндпоинт без переписывания парсера,
 * сохраняем «дыры» в шапке через placeholder.{slug}, а данные у них остаются
 * пустыми.
 */
class EmptyPlaceholderField extends ExportField
{
    public function __construct(protected string $slug) {}

    public function key(): string
    {
        return "placeholder.{$this->slug}";
    }

    public function name(): string
    {
        return $this->slug;
    }

    public function group(): string
    {
        return 'Заглушки';
    }

    public function isFilterable(): bool
    {
        return false;
    }

    public function isExportable(): bool
    {
        return true;
    }

    public function getValue(Product $product, ?User $clientUser = null): mixed
    {
        return '';
    }
}
