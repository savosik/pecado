<?php

namespace App\Services\ProductExport\Concerns;

/**
 * Transient-кеш на экземпляре модели, который пресет заполняет перед
 * mapping-ом чанка, а поля читают вместо повторного похода в сервисы/relations.
 *
 * Why не Eloquent-attributes: эти данные не приходят из БД и не должны
 * сериализоваться в toArray() или попадать в save(). Просто protected
 * свойство модели — Eloquent его игнорирует, но в рамках одного PHP-процесса
 * (одного чанка генерации) оно живёт и помогает экономить N+1.
 *
 * Использование:
 *   $product->setExportRowCache('price_result', $priceMap[$id] ?? null);
 *   $product->setExportRowCache('attr_index', $attrIndex);
 *   $product->getExportRowCache('price_result'); // в Field::getValue()
 *
 * Если кеш не установлен — поля делают fallback на старый путь, поэтому
 * trait совместим с любыми пресетами и не ломает прямой вызов Field-ов.
 */
trait HasExportRowCache
{
    /** @var array<string, mixed> */
    protected array $exportRowCache = [];

    public function setExportRowCache(string $key, mixed $value): static
    {
        $this->exportRowCache[$key] = $value;

        return $this;
    }

    public function getExportRowCache(string $key, mixed $default = null): mixed
    {
        return $this->exportRowCache[$key] ?? $default;
    }

    public function hasExportRowCache(string $key): bool
    {
        return array_key_exists($key, $this->exportRowCache);
    }
}
