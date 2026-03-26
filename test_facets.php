<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Product;
use App\Services\Product\CatalogFacetService;

$facetService = app(CatalogFacetService::class);

$baseQuery = Product::query()->where('id', 0); // Query that returns 0 products
$selectedBrandIds = [1];

$brands = $facetService->getBrandFacets($baseQuery, $selectedBrandIds);
echo "Brands facet:\n";
print_r($brands);

$selectedCategoryIds = [1];
$cats = $facetService->getCategoryFacets($baseQuery, $selectedCategoryIds);
echo "\nCategories facet:\n";
print_r($cats);

$selectedAttrValueIds = [10]; // just some ID
$attrs = $facetService->getAttributeFacets($baseQuery, $selectedAttrValueIds);
echo "\nAttributes facet:\n";
print_r($attrs);
