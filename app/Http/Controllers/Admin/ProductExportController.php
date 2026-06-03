<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExportFormat;
use App\Http\Controllers\Admin\Traits\RedirectsAfterSave;
use App\Http\Controllers\Traits\ProductExportFieldValidation;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Currency;
use App\Models\ProductExport;
use App\Models\Warehouse;
use App\Services\ProductExportService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class ProductExportController extends Controller
{
    use ProductExportFieldValidation;
    use RedirectsAfterSave;

    protected ProductExportService $exportService;

    public function __construct(ProductExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    public function index(Request $request)
    {
        $query = ProductExport::query()
            ->where('user_id', auth()->id())
            ->with('clientUser')
            ->latest();

        // Поиск
        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        // Пагинация
        $perPage = $request->input('per_page', 15);
        $exports = $query->paginate($perPage)->withQueryString();

        return Inertia::render('Admin/Pages/ProductExports/Index', [
            'exports' => $exports,
            'filters' => $request->only(['search', 'per_page']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Admin/Pages/ProductExports/Create', [
            'availableFilters' => $this->exportService->getAvailableFilters(),
            'availableFields' => $this->exportService->getAvailableFields(),
            'currencies' => Currency::select('id', 'code', 'name', 'symbol', 'is_base')->orderByDesc('is_base')->orderBy('code')->get(),
            'formats' => collect(ExportFormat::cases())->map(fn ($f) => [
                'value' => $f->value,
                'label' => $f->label(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'format' => 'required|string|in:json,csv,xml,xls',
            'filters' => 'nullable|array',
            'fields' => 'required|array|min:1',
            'is_active' => 'boolean',
            'client_user_id' => 'required|exists:users,id',
        ], $this->exportFieldRules()), [
            'name.required' => 'Название обязательно',
            'name.max' => 'Название не должно превышать 255 символов',
            'format.required' => 'Формат обязателен',
            'format.in' => 'Недопустимый формат выгрузки',
            'fields.required' => 'Выберите хотя бы одно поле',
            'fields.min' => 'Выберите хотя бы одно поле',
            'client_user_id.required' => 'Клиент обязателен для расчёта цен и остатков',
            'client_user_id.exists' => 'Выбранный клиент не найден',
        ]);

        $validated['user_id'] = auth()->id();

        $export = ProductExport::create($validated);

        return $this->redirectAfterSave($request, 'admin.product-exports.index', 'admin.product-exports.edit', $export, 'Выгрузка успешно создана');
    }

    public function show(ProductExport $productExport)
    {
        if ($productExport->user_id !== auth()->id()) {
            abort(403);
        }

        $productExport->load('clientUser');

        return Inertia::render('Admin/Pages/ProductExports/Show', [
            'export' => [
                'id' => $productExport->id,
                'name' => $productExport->name,
                'format' => $productExport->format,
                'is_active' => $productExport->is_active,
                'fields' => $productExport->fields,
                'filters' => $productExport->filters,
                'client_user' => $productExport->clientUser ? [
                    'id' => $productExport->clientUser->id,
                    'name' => $productExport->clientUser->name,
                    'email' => $productExport->clientUser->email,
                ] : null,
                'created_at' => $productExport->created_at?->format('d.m.Y H:i'),
                'updated_at' => $productExport->updated_at?->format('d.m.Y H:i'),
            ],
        ]);
    }

    public function edit(ProductExport $productExport)
    {
        // Ensure user can only edit their own exports
        if ($productExport->user_id !== auth()->id()) {
            abort(403);
        }

        // Load clientUser with full name for display + lastRun for stats panel.
        $productExport->load('clientUser', 'lastRun');

        $lastRun = $productExport->lastRun;
        $lastRunPayload = $lastRun ? [
            'id' => $lastRun->id,
            'status' => $lastRun->status,
            'started_at' => $lastRun->started_at?->toISOString(),
            'finished_at' => $lastRun->finished_at?->toISOString(),
            'duration_ms' => $lastRun->duration_ms,
            'queued_for_ms' => $lastRun->queued_for_ms,
            'rows_count' => $lastRun->rows_count,
            'bytes' => $lastRun->bytes,
            'steps_json' => $lastRun->steps_json,
            'error_message' => $lastRun->error_message,
        ] : null;

        return Inertia::render('Admin/Pages/ProductExports/Edit', [
            'export' => $productExport,
            'lastRun' => $lastRunPayload,
            'availableFilters' => $this->exportService->getAvailableFilters(),
            'availableFields' => $this->exportService->getAvailableFields(),
            'currencies' => Currency::select('id', 'code', 'name', 'symbol', 'is_base')->orderByDesc('is_base')->orderBy('code')->get(),
            'formats' => collect(ExportFormat::cases())->map(fn ($f) => [
                'value' => $f->value,
                'label' => $f->label(),
            ]),
        ]);
    }

    public function update(Request $request, ProductExport $productExport)
    {
        if ($productExport->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'format' => 'required|string|in:json,csv,xml,xls',
            'filters' => 'nullable|array',
            'fields' => 'required|array|min:1',
            'is_active' => 'boolean',
            'client_user_id' => 'required|exists:users,id',
        ], $this->exportFieldRules()), [
            'name.required' => 'Название обязательно',
            'name.max' => 'Название не должно превышать 255 символов',
            'format.required' => 'Формат обязателен',
            'format.in' => 'Недопустимый формат выгрузки',
            'fields.required' => 'Выберите хотя бы одно поле',
            'fields.min' => 'Выберите хотя бы одно поле',
            'client_user_id.required' => 'Клиент обязателен для расчёта цен и остатков',
            'client_user_id.exists' => 'Выбранный клиент не найден',
        ]);

        $productExport->update($validated);

        return $this->redirectAfterSave($request, 'admin.product-exports.index', 'admin.product-exports.edit', $productExport, 'Выгрузка успешно обновлена');
    }

    public function destroy(ProductExport $productExport)
    {
        if ($productExport->user_id !== auth()->id()) {
            abort(403);
        }

        $productExport->delete();

        return redirect()->route('admin.product-exports.index')->with('success', 'Выгрузка успешно удалена');
    }

    /**
     * Preview export - returns first N items matching filters.
     */
    public function preview(Request $request)
    {
        $request->validate(array_merge([
            'filters' => 'nullable|array',
            'fields' => 'required|array|min:1',
            'client_user_id' => 'nullable|exists:users,id',
        ], $this->exportFieldRules()));

        $result = $this->exportService->preview(
            $request->input('filters', []),
            $request->input('fields', []),
            $request->input('client_user_id'),
            20
        );

        return response()->json($result);
    }

    /**
     * Get filter options for relation fields (brands, categories, warehouses, certificates).
     */
    public function filterOptions(Request $request)
    {
        $type = $request->input('type');
        $search = $request->input('search', '');

        $data = match ($type) {
            'brands' => Brand::query()
                ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->select('id', 'name')
                ->orderBy('name')
                ->limit(50)
                ->get(),
            'categories' => Category::query()
                ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->select('id', 'name')
                ->orderBy('name')
                ->limit(50)
                ->get(),
            'warehouses' => Warehouse::query()
                ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->select('id', 'name')
                ->orderBy('name')
                ->limit(50)
                ->get(),
            'certificates' => Certificate::query()
                ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->select('id', 'name')
                ->orderBy('name')
                ->limit(50)
                ->get(),
            default => collect(),
        };

        return response()->json($data);
    }
}
