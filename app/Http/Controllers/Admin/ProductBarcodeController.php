<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Models\ProductBarcode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductBarcodeController extends AdminController
{
    use RedirectsAfterSave;

    /**
     * Display a listing of the barcodes.
     */
    public function index(Request $request): Response
    {
        $query = ProductBarcode::query()->with('product');

        // Поиск
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', function ($qp) use ($search) {
                        $qp->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Сортировка
        $sortBy = $request->input('sort_by', 'id');
        $sortOrder = $request->input('sort_order', 'desc');

        $allowedSortFields = ['id', 'barcode', 'created_at'];
        if (in_array($sortBy, $allowedSortFields)) {
            $query->orderBy($sortBy, $sortOrder);
        }

        // Пагинация
        $perPage = (int) $request->input('per_page', 15);
        $perPage = min(max($perPage, 5), 100);

        $barcodes = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/ProductBarcodes/Index', [
            'barcodes' => $barcodes,
            'filters' => [
                'search' => $search,
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Show the form for creating a new barcode.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Pages/ProductBarcodes/Create');
    }

    /**
     * Store a newly created barcode in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'barcode' => 'required|string|max:255|unique:product_barcodes,barcode',
        ]);

        $barcode = ProductBarcode::create($validated);

        return $this->redirectAfterSave($request, 'admin.product-barcodes.index', 'admin.product-barcodes.edit', $barcode, 'Штрихкод успешно добавлен');
    }

    /**
     * Show the form for editing the specified barcode.
     */
    public function edit(ProductBarcode $productBarcode): Response
    {
        $productBarcode->load(['product.media', 'product.barcodes', 'product.brand']);

        $currentProduct = null;

        if ($productBarcode->product !== null) {
            $product = $productBarcode->product;

            $currentProduct = [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'image_url' => $product->getFirstMediaUrl('main'),
                'price' => $product->base_price,
                'barcode' => $product->barcode,
                'barcodes' => $product->barcodes->pluck('barcode')->toArray(),
                'brand_name' => $product->brand ? $product->brand->name : null,
            ];
        }

        return Inertia::render('Admin/Pages/ProductBarcodes/Edit', [
            'productBarcode' => $productBarcode,
            'currentProduct' => $currentProduct,
        ]);
    }

    /**
     * Update the specified barcode in storage.
     */
    public function update(Request $request, ProductBarcode $productBarcode): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'barcode' => 'required|string|max:255|unique:product_barcodes,barcode,'.$productBarcode->id,
        ]);

        $productBarcode->update($validated);

        return $this->redirectAfterSave($request, 'admin.product-barcodes.index', 'admin.product-barcodes.edit', $productBarcode, 'Штрихкод успешно обновлен');
    }

    /**
     * Remove the specified barcode from storage.
     */
    public function destroy(ProductBarcode $productBarcode): RedirectResponse
    {
        $productBarcode->delete();

        return redirect()->route('admin.product-barcodes.index')->with('success', 'Штрихкод успешно удален');
    }
}
