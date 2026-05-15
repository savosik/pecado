<?php

namespace App\Http\Controllers\User;

use App\Enums\ExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ProductExportFieldValidation;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\Currency;
use App\Models\ProductExport;
use App\Models\Warehouse;
use App\Services\ProductExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class ProductExportController extends Controller
{
    use ProductExportFieldValidation;

    protected ProductExportService $exportService;

    private const SORT_OPTIONS = [
        'created_desc' => 'Сначала новые',
        'created_asc' => 'Сначала старые',
        'name_asc' => 'По названию (А → Я)',
        'name_desc' => 'По названию (Я → А)',
        'last_downloaded_desc' => 'Сначала недавно скачанные',
        'last_downloaded_asc' => 'Сначала давно скачанные',
    ];

    public function __construct(ProductExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->input('search', ''));
        $sort = (string) $request->input('sort', 'created_desc');
        if (! array_key_exists($sort, self::SORT_OPTIONS)) {
            $sort = 'created_desc';
        }

        $createdFrom = $request->filled('created_from') ? $request->input('created_from') : null;
        $createdTo = $request->filled('created_to') ? $request->input('created_to') : null;
        $lastDownloadedFrom = $request->filled('last_downloaded_from') ? $request->input('last_downloaded_from') : null;
        $lastDownloadedTo = $request->filled('last_downloaded_to') ? $request->input('last_downloaded_to') : null;

        $isActive = $request->input('is_active');
        if (! in_array($isActive, ['1', '0', 1, 0, true, false], true)) {
            $isActive = null;
        }

        // Кабинет показывает только выгрузки, которые клиент СОЗДАЁТ ДЛЯ СЕБЯ
        // (user_id = client_user_id = я). Админские выгрузки, которые этот же
        // пользователь сделал для других клиентов через /admin, остаются в
        // админке и не светятся здесь.
        $query = ProductExport::query()
            ->where('user_id', Auth::id())
            ->where('client_user_id', Auth::id())
            ->whereNull('preset'); // Пресеты отображаются отдельно в карточках

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('filters_text', 'like', $like);
            });
        }

        if ($createdFrom !== null) {
            $query->whereDate('created_at', '>=', $createdFrom);
        }
        if ($createdTo !== null) {
            $query->whereDate('created_at', '<=', $createdTo);
        }
        if ($lastDownloadedFrom !== null) {
            $query->whereDate('last_downloaded_at', '>=', $lastDownloadedFrom);
        }
        if ($lastDownloadedTo !== null) {
            $query->whereDate('last_downloaded_at', '<=', $lastDownloadedTo);
        }
        if ($isActive !== null) {
            $query->where('is_active', (bool) $isActive);
        }

        match ($sort) {
            'created_asc' => $query->orderBy('created_at')->orderBy('id'),
            'name_asc' => $query->orderBy('name')->orderBy('id'),
            'name_desc' => $query->orderByDesc('name')->orderBy('id'),
            'last_downloaded_desc' => $query->orderByRaw('last_downloaded_at IS NULL')->orderByDesc('last_downloaded_at')->orderBy('id'),
            'last_downloaded_asc' => $query->orderByRaw('last_downloaded_at IS NULL')->orderBy('last_downloaded_at')->orderBy('id'),
            default => $query->orderByDesc('created_at')->orderBy('id'),
        };

        $perPage = (int) $request->input('per_page', 15);
        if ($perPage <= 0 || $perPage > 100) {
            $perPage = 15;
        }
        $exports = $query->paginate($perPage)->withQueryString();

        return Inertia::render('User/Cabinet/ProductExports/Index', [
            'exports' => $exports,
            'filters' => [
                'search' => $search,
                'sort' => $sort,
                'created_from' => $createdFrom,
                'created_to' => $createdTo,
                'last_downloaded_from' => $lastDownloadedFrom,
                'last_downloaded_to' => $lastDownloadedTo,
                'is_active' => $isActive === null ? null : (bool) $isActive,
                'per_page' => $perPage,
            ],
            'sortOptions' => self::SORT_OPTIONS,
        ]);
    }

    public function create()
    {
        return Inertia::render('User/Cabinet/ProductExports/Form', [
            'export' => null,
            'availableFilters' => $this->rewriteFilterUrls($this->exportService->getAvailableFilters()),
            'availableFields' => $this->exportService->getAvailableFields(),
            'currencies' => Currency::select('id', 'code', 'name', 'symbol', 'is_base')
                ->orderByDesc('is_base')
                ->orderBy('code')
                ->get(),
            'formats' => collect(ExportFormat::cases())->map(fn ($f) => [
                'value' => $f->value,
                'label' => $f->label(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateExport($request);
        $validated['user_id'] = Auth::id();
        $validated['client_user_id'] = Auth::id();

        $export = ProductExport::create($validated);

        return redirect()->route('cabinet.product-exports.edit', $export)
            ->with('success', 'Выгрузка успешно создана');
    }

    public function edit(ProductExport $productExport)
    {
        $this->authorizeExport($productExport);

        return Inertia::render('User/Cabinet/ProductExports/Form', [
            'export' => $productExport,
            'availableFilters' => $this->rewriteFilterUrls($this->exportService->getAvailableFilters()),
            'availableFields' => $this->exportService->getAvailableFields(),
            'currencies' => Currency::select('id', 'code', 'name', 'symbol', 'is_base')
                ->orderByDesc('is_base')
                ->orderBy('code')
                ->get(),
            'formats' => collect(ExportFormat::cases())->map(fn ($f) => [
                'value' => $f->value,
                'label' => $f->label(),
            ]),
        ]);
    }

    public function update(Request $request, ProductExport $productExport)
    {
        $this->authorizeExport($productExport);

        $validated = $this->validateExport($request);
        $productExport->update($validated);

        return redirect()->route('cabinet.product-exports.edit', $productExport)
            ->with('success', 'Выгрузка успешно обновлена');
    }

    public function destroy(ProductExport $productExport)
    {
        $this->authorizeExport($productExport);
        $productExport->delete();

        return redirect()->route('cabinet.product-exports.index')
            ->with('success', 'Выгрузка успешно удалена');
    }

    public function preview(Request $request)
    {
        $request->validate(array_merge([
            'filters' => 'nullable|array',
            'fields' => 'required|array|min:1',
        ], $this->exportFieldRules()));

        $result = $this->exportService->preview(
            $request->input('filters', []),
            $request->input('fields', []),
            Auth::id(),
            20
        );

        return response()->json($result);
    }

    public function filterOptions(Request $request)
    {
        $type = $request->input('type');
        $search = $request->input('search', $request->input('query', ''));

        $data = match ($type) {
            'brands' => Brand::query()
                ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
                ->select('id', 'name')
                ->orderBy('name')
                ->limit(50)
                ->get(),
            'categories' => Category::query()
                ->where('is_active', true)
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

    private function authorizeExport(ProductExport $export): void
    {
        // Кабинет видит только «свои-для-себя» выгрузки. Админская выгрузка
        // того же владельца, но созданная для другого client_user_id,
        // редактируется через /admin/product-exports, не здесь.
        $isOwn = $export->user_id === Auth::id() && $export->client_user_id === Auth::id();
        abort_if(! $isOwn, 403, 'Доступ запрещён.');
    }

    private function validateExport(Request $request): array
    {
        return $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'format' => 'required|string|in:json,csv,xml,xls',
            'filters' => 'nullable|array',
            'fields' => 'required|array|min:1',
            'is_active' => 'boolean',
        ], $this->exportFieldRules()), [
            'name.required' => 'Название обязательно',
            'name.max' => 'Название не должно превышать 255 символов',
            'format.required' => 'Формат обязателен',
            'format.in' => 'Недопустимый формат выгрузки',
            'fields.required' => 'Выберите хотя бы одно поле',
            'fields.min' => 'Выберите хотя бы одно поле',
        ]);
    }

    /**
     * Rewrite admin search_url in filter definitions to use the cabinet's filterOptions endpoint.
     */
    private function rewriteFilterUrls(array $filters): array
    {
        $urlMap = [
            '/admin/brands/search' => '/cabinet/product-exports/filter-options?type=brands',
            '/admin/categories/search' => '/cabinet/product-exports/filter-options?type=categories',
            '/admin/warehouses/search' => '/cabinet/product-exports/filter-options?type=warehouses',
            '/admin/certificates/search' => '/cabinet/product-exports/filter-options?type=certificates',
            '/admin/product-models/search' => '/cabinet/product-exports/filter-options?type=models',
        ];

        foreach ($filters as &$group) {
            if (isset($group['fields'])) {
                foreach ($group['fields'] as &$field) {
                    if (isset($field['search_url']) && isset($urlMap[$field['search_url']])) {
                        $field['search_url'] = $urlMap[$field['search_url']];
                    }
                }
            }
        }

        return $filters;
    }
}
