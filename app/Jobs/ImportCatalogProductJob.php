<?php

namespace App\Jobs;

use App\Models\Attribute;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Product;
use App\Models\ProductAttributeValue;
use App\Models\ProductBarcode;
use App\Models\ProductModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ImportCatalogProductJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param array $itemData Parsed XML item data as associative array
     * @param bool $skipMedia Whether to skip media download
     */
    public function __construct(
        public array $itemData,
        public bool $skipMedia = false,
    ) {
        $this->onQueue('catalog-import');
    }

    public function handle(): void
    {
        $data = $this->itemData;

        DB::transaction(function () use ($data) {
            // 1. Import category hierarchy
            $leafCategory = $this->importCategoryPath($data['category_path'] ?? '');

            // 2. Import brand
            $brand = $this->importBrand($data);

            // 3. Import product model
            $productModel = $this->importProductModel($data);

            // 4. Import product
            $product = $this->importProduct($data, $brand, $productModel);

            // 5. Attach product to leaf category
            if ($leafCategory) {
                $product->categories()->syncWithoutDetaching([$leafCategory->id]);
            }

            // 6. Import barcodes
            $this->importBarcodes($product, $data);

            // 7. Import attributes (parameters)
            $this->importAttributes($product, $data, $leafCategory);

            // 8. Import certificates
            $this->importCertificates($product, $data);
        });

        // 9. Dispatch media download job if not skipped
        if (!$this->skipMedia) {
            $product = Product::where('external_id', $data['uid'])->first();
            if ($product) {
                DownloadProductMediaJob::dispatch($product->id, $data);
            }
        }

        Log::info("Импортирован товар: {$data['name']} [{$data['code']}]");
    }

    private function importCategoryPath(string $categoryPath): ?Category
    {
        if (empty($categoryPath)) {
            return null;
        }

        $parts = explode('/', $categoryPath);
        $parentId = null;
        $lastCategory = null;

        foreach ($parts as $partName) {
            $partName = trim($partName);
            if (empty($partName)) {
                continue;
            }

            $category = Category::firstOrCreate(
                [
                    'name' => $partName,
                    'parent_id' => $parentId,
                ],
                [
                    'name' => $partName,
                    'parent_id' => $parentId,
                ]
            );

            $lastCategory = $category;
            $parentId = $category->id;
        }

        return $lastCategory;
    }

    private function importBrand(array $data): ?Brand
    {
        $brandUid = $data['brand_uid'] ?? '';
        $brandName = $data['brand_name'] ?? '';

        if (empty($brandUid) || empty($brandName)) {
            return null;
        }

        return Brand::firstOrCreate(
            ['external_id' => $brandUid],
            [
                'name' => $brandName,
                'slug' => Str::slug($brandName) ?: Str::slug(Str::transliterate($brandName)),
            ]
        );
    }

    private function importProductModel(array $data): ?ProductModel
    {
        $modelUid = $data['model_uid'] ?? '';
        $modelName = $data['model_name'] ?? '';
        $groupCode = $data['group_code'] ?? '';

        if (empty($modelUid) || empty($modelName)) {
            return null;
        }

        return ProductModel::firstOrCreate(
            ['external_id' => $modelUid],
            [
                'name' => $modelName,
                'code' => $groupCode ?: null,
            ]
        );
    }

    private function importProduct(array $data, ?Brand $brand, ?ProductModel $productModel): Product
    {
        return Product::updateOrCreate(
            ['external_id' => $data['uid']],
            [
                'name' => $data['name'],
                'code' => $data['code'] ?: null,
                'sku' => $data['sku'] ?: null,
                'slug' => $data['slug'] ?: null,
                'url' => $data['url'] ?: null,
                'barcode' => $data['barcode'] ?: null,
                'tnved' => $data['tnved'] ?: null,
                'is_new' => ($data['novelty'] ?? '') === 'Да',
                'is_marked' => ($data['marked'] ?? '') === 'Да',
                'is_liquidation' => ($data['liquidation'] ?? '') === 'Да',
                'for_marketplaces' => ($data['for_marketplaces'] ?? '') === 'Да',
                'description' => $data['description'] ?: null,
                'description_html' => $data['description_html'] ?: null,
                'short_description' => $data['short_description'] ?: null,
                'brand_id' => $brand?->id,
                'model_id' => $productModel?->id,
                'base_price' => 0,
            ]
        );
    }

    private function importBarcodes(Product $product, array $data): void
    {
        $product->barcodes()->delete();

        foreach ($data['barcodes'] ?? [] as $barcodeValue) {
            if (!empty($barcodeValue)) {
                ProductBarcode::create([
                    'product_id' => $product->id,
                    'barcode' => $barcodeValue,
                ]);
            }
        }
    }

    private function importAttributes(Product $product, array $data, ?Category $leafCategory): void
    {
        $product->attributeValues()->delete();

        foreach ($data['parameters'] ?? [] as $param) {
            $attrName = $param['name'] ?? '';
            $attrValue = $param['value'] ?? '';

            if (empty($attrName) || $attrValue === '') {
                continue;
            }

            $attribute = $this->getOrCreateAttribute($attrName, $attrValue);

            if ($leafCategory) {
                $attribute->categories()->syncWithoutDetaching([$leafCategory->id]);
            }

            $pav = new ProductAttributeValue([
                'product_id' => $product->id,
                'attribute_id' => $attribute->id,
            ]);

            if ($attribute->type === 'select') {
                // Для справочников: находим или создаём значение в справочнике
                $attributeValue = \App\Models\AttributeValue::firstOrCreate(
                    [
                        'attribute_id' => $attribute->id,
                        'value' => $attrValue,
                    ],
                    [
                        'sort_order' => $attribute->values()->count(),
                    ]
                );
                $pav->attribute_value_id = $attributeValue->id;
            } elseif ($attribute->type === 'boolean') {
                // Для булевых: конвертируем текст в boolean
                $trueValues = ['да', 'yes', 'true', '1', 'on'];
                $pav->boolean_value = in_array(mb_strtolower(trim($attrValue)), $trueValues);
            } elseif ($attribute->type === 'number' && is_numeric($attrValue)) {
                $pav->number_value = (float) $attrValue;
            } else {
                $pav->text_value = $attrValue;
            }

            $pav->save();
        }
    }

    private function getOrCreateAttribute(string $name, string $sampleValue): Attribute
    {
        $slug = Str::slug($name);
        if (empty($slug)) {
            $slug = Str::slug(Str::transliterate($name));
        }

        $originalSlug = $slug;
        $counter = 1;
        while (Attribute::where('slug', $slug)->whereNot('name', $name)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }

        $type = is_numeric($sampleValue) ? 'number' : 'string';

        return Attribute::firstOrCreate(
            ['name' => $name],
            [
                'slug' => $slug,
                'type' => $type,
                'is_filterable' => false,
                'sort_order' => 0,
            ]
        );
    }

    private function importCertificates(Product $product, array $data): void
    {
        $certificateIds = [];

        foreach ($data['certificates'] ?? [] as $certUidStr) {
            if (empty($certUidStr)) {
                continue;
            }

            // Привязываем только сертификаты, которые уже существуют в БД
            $certificate = Certificate::where('external_id', $certUidStr)->first();

            if ($certificate) {
                $certificateIds[] = $certificate->id;
            }
        }

        $product->certificates()->sync($certificateIds);
    }
}
