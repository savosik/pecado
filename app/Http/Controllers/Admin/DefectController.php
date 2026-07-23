<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDefectPriceRequest;
use App\Models\ProductDefect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Уценка глазами закупщика.
 *
 * Кладовщик заводит партии в /wms, здесь закупщик (buyer-manager) назначает цену
 * и включает видимость на сайте. Публикация без цены запрещена на бэкенде —
 * не только в UI.
 */
class DefectController extends Controller
{
    public function __construct(
        private readonly DefectStockServiceInterface $defectStock
    ) {}

    public function index(Request $request): Response
    {
        $filter = $request->string('filter')->toString();

        $defects = ProductDefect::query()
            ->with(['product:id,name,sku', 'warehouse:id,name', 'media', 'creator:id,name', 'pricer:id,name'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());

                $query->where(function ($q) use ($search) {
                    $q->where('defect_description', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                        );
                });
            })
            ->when($filter === 'unpriced', fn ($q) => $q->whereNull('closed_at')->whereNull('price'))
            ->when($filter === 'unpublished', fn ($q) => $q->whereNull('closed_at')->whereNotNull('price')->where('is_published', false))
            ->when($filter === 'published', fn ($q) => $q->whereNull('closed_at')->where('is_published', true))
            ->when($filter === 'closed', fn ($q) => $q->whereNotNull('closed_at'))
            ->when($filter === '' || $filter === 'open', fn ($q) => $q->whereNull('closed_at'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $available = $this->defectStock->availableMap($defects->getCollection());

        return Inertia::render('Admin/Pages/Defects/Index', [
            'defects' => $defects->through(
                fn (ProductDefect $defect) => $this->presentDefect($defect, $available)
            ),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'filter' => $filter ?: 'open',
            ],
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Назначить цену уценки. Фиксируем автора цены для истории.
     */
    public function updatePrice(UpdateDefectPriceRequest $request, ProductDefect $defect): RedirectResponse
    {
        if ($defect->isClosed()) {
            return back()->with('error', 'Партия закрыта — цену изменить нельзя.');
        }

        $defect->update([
            'price' => $request->float('price'),
            'priced_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Цена сохранена.');
    }

    /**
     * Включить/выключить видимость партии на сайте.
     *
     * Публиковать без цены нельзя — иначе на витрине окажется товар, который
     * нельзя купить (цена обязательна для корзины и заказа).
     */
    public function togglePublish(Request $request, ProductDefect $defect): RedirectResponse
    {
        if (! $request->user()->can('defects.publish')) {
            abort(403);
        }

        if ($defect->isClosed()) {
            return back()->with('error', 'Партия закрыта — публикацию изменить нельзя.');
        }

        $publish = $request->boolean('is_published');

        if ($publish && ! $defect->canBePublished()) {
            return back()->with('error', 'Нельзя опубликовать партию без цены. Сначала назначьте цену.');
        }

        $defect->update(['is_published' => $publish]);

        return back()->with(
            'success',
            $publish ? 'Партия опубликована на сайте.' : 'Партия снята с публикации.'
        );
    }

    /**
     * Удалить партию (мягко — модель под SoftDeletes).
     *
     * Партию с заказами удалять нельзя: резерв считается по order_items, и
     * удаление увело бы из-под заказа его позицию. Закрытую партию удалить
     * можно — это история склада, а не продаж.
     */
    public function destroy(Request $request, ProductDefect $defect): RedirectResponse
    {
        if (! $request->user()->can('defects.delete')) {
            abort(403);
        }

        if ($this->defectStock->reserved($defect) > 0) {
            return back()->with('error', 'Партию нельзя удалить: по ней есть заказы. Сначала отмените их.');
        }

        $defect->delete();

        return back()->with('success', 'Партия удалена.');
    }

    /**
     * @return array{total: int, unpriced: int, unpublished: int, published: int}
     */
    private function stats(): array
    {
        $row = ProductDefect::query()
            ->whereNull('closed_at')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN price IS NULL THEN 1 ELSE 0 END) as unpriced,
                SUM(CASE WHEN price IS NOT NULL AND is_published = 0 THEN 1 ELSE 0 END) as unpublished,
                SUM(CASE WHEN is_published = 1 THEN 1 ELSE 0 END) as published
            ')
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'unpriced' => (int) ($row->unpriced ?? 0),
            'unpublished' => (int) ($row->unpublished ?? 0),
            'published' => (int) ($row->published ?? 0),
        ];
    }

    /**
     * @param  array<int, int>  $availableMap
     * @return array<string, mixed>
     */
    private function presentDefect(ProductDefect $defect, array $availableMap): array
    {
        return [
            'id' => $defect->id,
            'defect_description' => $defect->defect_description,
            'quantity' => $defect->quantity,
            'available_quantity' => $availableMap[$defect->id] ?? 0,
            'reserved_quantity' => $defect->quantity - ($availableMap[$defect->id] ?? 0),
            'price' => $defect->price !== null ? (float) $defect->price : null,
            'is_published' => $defect->is_published,
            'closed_at' => $defect->closed_at?->toIso8601String(),
            'closed_reason_label' => $defect->closed_reason?->label(),
            'created_by_name' => $defect->creator?->name,
            'priced_by_name' => $defect->pricer?->name,
            'created_at' => $defect->created_at?->toIso8601String(),
            'product' => [
                'id' => $defect->product->id,
                'name' => $defect->product->name,
                'sku' => $defect->product->sku,
            ],
            'warehouse' => [
                'id' => $defect->warehouse->id,
                'name' => $defect->warehouse->name,
            ],
            'photos' => $defect->getMedia(ProductDefect::MEDIA_COLLECTION)->map(fn ($media) => [
                'id' => $media->id,
                'url' => $media->getUrl(),
                'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : $media->getUrl(),
            ])->values(),
        ];
    }
}
