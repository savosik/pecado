<?php

namespace App\Services\ProductExport\Presets;

use Illuminate\Support\Collection;

/**
 * Реестр всех доступных пресетов стандартных выгрузок.
 */
class PresetRegistry
{
    /** @var Collection<string, PresetInterface> */
    protected Collection $presets;

    public function __construct(
        YmlPreset $yml,
        ShopifyCsvPreset $shopify,
        WooCommerceCsvPreset $woocommerce,
        VkMarketCsvPreset $vk,
        GoogleMerchantXmlPreset $google,
        TildaCsvPreset $tilda,
        OpenCartCsvPreset $opencart,
        CsCartCsvPreset $cscart,
        JsonCatalogPreset $json,
    ) {
        $this->presets = collect([
            $yml, $shopify, $woocommerce, $vk, $google, $tilda, $opencart, $cscart, $json,
        ])->keyBy(fn (PresetInterface $p) => $p->key());
    }

    /**
     * Получить пресет по ключу.
     */
    public function resolve(string $key): ?PresetInterface
    {
        return $this->presets->get($key);
    }

    /**
     * Все доступные пресеты.
     */
    public function all(): Collection
    {
        return $this->presets;
    }

    /**
     * Данные для фронтенда (список карточек).
     */
    public function toArray(): array
    {
        return $this->presets->map(fn (PresetInterface $preset) => [
            'key' => $preset->key(),
            'name' => $preset->name(),
            'description' => $preset->description(),
            'extension' => $preset->fileExtension(),
            'color' => $preset->color(),
            'icon' => $preset->icon(),
        ])->values()->toArray();
    }
}
