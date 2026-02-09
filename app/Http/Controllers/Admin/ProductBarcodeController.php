<?php

namespace App\Http\Controllers\Admin;

use App\Models\ProductBarcode;
use App\Models\Product;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;

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
                    ->orWhereHas('product', function($qp) use ($search) {
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
        return Inertia::render('Admin/Pages/ProductBarcodes/Create', [
            'products' => Product::select('id', 'name')->orderBy('name')->get(),
        ]);
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
        return Inertia::render('Admin/Pages/ProductBarcodes/Edit', [
            'productBarcode' => $productBarcode,
            'products' => Product::select('id', 'name')->orderBy('name')->get(),
        ]);
    }

    /**
     * Update the specified barcode in storage.
     */
    public function update(Request $request, ProductBarcode $productBarcode): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'barcode' => 'required|string|max:255|unique:product_barcodes,barcode,' . $productBarcode->id,
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
