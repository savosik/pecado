<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BulkDefectPriceRequest;
use App\Http\Requests\Admin\UpdateDefectPriceRequest;
use App\Models\ProductDefect;
use App\Services\Defect\DefectCoverageService;
use App\Services\Defect\DefectReferencePriceService;
use App\Services\SimpleXlsxExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        private readonly DefectStockServiceInterface $defectStock,
        private readonly DefectReferencePriceService $referencePrices,
        private readonly DefectCoverageService $defectCoverage
    ) {}

    public function index(Request $request): Response
    {
        $filter = $request->string('filter')->toString();

        $defects = $this->defectsQuery($request, $filter)
            ->paginate(20)
            ->withQueryString();

        $page = $defects->getCollection();
        $available = $this->defectStock->availableMap($page);
        $reference = $this->referencePrices->forProducts($page->pluck('product_id')->all());
        $stock = $this->stockTotals($page);

        return Inertia::render('Admin/Pages/Defects/Index', [
            'defects' => $defects->through(
                fn (ProductDefect $defect) => $this->presentDefect($defect, $available, $reference, $stock)
            ),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'filter' => $filter ?: 'open',
            ],
            'stats' => $this->stats(),
        ]);
    }

    /**
     * Выгрузка списка уценки в XLSX по текущему фильтру и поиску.
     *
     * Выгружаются все строки отбора, а не видимая страница: файл нужен для
     * сверки с 1С, и обрезанный по пагинации он бессмысленен. Идём чанками —
     * справочные цены и остатки считаются на каждый чанк отдельно, чтобы
     * память не зависела от размера выборки.
     */
    public function export(Request $request, SimpleXlsxExporter $exporter): StreamedResponse
    {
        $filter = $request->string('filter')->toString();

        $rows = [];

        $this->defectsQuery($request, $filter)
            ->chunk(300, function (Collection $chunk) use (&$rows) {
                $available = $this->defectStock->availableMap($chunk);
                $reference = $this->referencePrices->forProducts($chunk->pluck('product_id')->all());
                $stock = $this->stockTotals($chunk);

                foreach ($chunk as $defect) {
                    $rows[] = $this->exportRow($defect, $available, $reference, $stock);
                }
            });

        return $exporter->stream(
            'defects-'.($filter ?: 'open').'-'.now()->format('Y-m-d'),
            [
                'Партия №', 'Код 1С', 'Артикул', 'Товар', 'Склад', 'Дефект',
                'Свободно 1С', 'Разобрано партиями', 'Не разобрано',
                'Заведено складом', 'В резерве', 'Доступно к продаже',
                'Цена клиента, ₽', 'Статус цены клиента', 'Цена уценки, ₽', 'Скидка от цены клиента, %',
                'На сайте', 'Состояние партии', 'Заведено кем', 'Заведено когда',
                'Цену назначил', 'Закрыта когда', 'Причина закрытия',
            ],
            $rows,
            'Уценка',
        );
    }

    /**
     * Общий отбор для списка и выгрузки: фильтры экрана должны совпадать с
     * тем, что уходит в файл, — иначе выгрузка перестаёт быть сверкой.
     *
     * @return Builder<ProductDefect>
     */
    private function defectsQuery(Request $request, string $filter): Builder
    {
        return ProductDefect::query()
            ->with(['product:id,name,sku,code', 'warehouse:id,name', 'media', 'creator:id,name', 'pricer:id,name'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());

                $query->where(function ($q) use ($search) {
                    $q->where('defect_description', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                        );
                });
            })
            ->when($filter === 'unpriced', fn ($q) => $q->whereNull('closed_at')->whereNull('price'))
            ->when($filter === 'unpublished', fn ($q) => $q->whereNull('closed_at')->whereNotNull('price')->where('is_published', false))
            ->when($filter === 'published', fn ($q) => $q->whereNull('closed_at')->where('is_published', true))
            ->when($filter === 'closed', fn ($q) => $q->whereNotNull('closed_at'))
            ->when($filter === '' || $filter === 'open', fn ($q) => $q->whereNull('closed_at'))
            ->latest('id');
    }

    /**
     * Остаток 1С и объём открытых партий по товарам выборки.
     *
     * @param  Collection<int, ProductDefect>  $defects
     * @return array<string, array{stock: int, covered: int}>
     */
    private function stockTotals(Collection $defects): array
    {
        return $this->defectCoverage->pairTotals(
            $defects->map(fn (ProductDefect $defect) => [
                (int) $defect->product_id,
                (int) $defect->warehouse_id,
            ])->all()
        );
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
     * Массово проставить цены выбранным партиям от справочной цены клиента.
     *
     * Скидка считается от той же цены, что подсвечена в таблице: справочную
     * цену пересчитываем на бэкенде, с фронта приходят только id и процент.
     * Партии без справочной цены и закрытые молча пропускаем — о них
     * рассказываем в итоговом сообщении.
     */
    public function bulkPrice(BulkDefectPriceRequest $request): RedirectResponse
    {
        /** @var array<int, int> $ids */
        $ids = $request->input('ids', []);
        $discount = (float) $request->input('discount_percent', 0);

        $defects = ProductDefect::query()->whereIn('id', $ids)->get();
        $reference = $this->referencePrices->forProducts($defects->pluck('product_id')->all());

        $updated = 0;
        $skippedClosed = 0;
        $skippedNoPrice = 0;

        foreach ($defects as $defect) {
            if ($defect->isClosed()) {
                $skippedClosed++;

                continue;
            }

            $base = $reference[$defect->product_id]['price'] ?? null;

            if ($base === null || $base <= 0) {
                $skippedNoPrice++;

                continue;
            }

            $price = round($base * (1 - $discount / 100), 2);

            if ($price <= 0) {
                $skippedNoPrice++;

                continue;
            }

            $defect->update([
                'price' => $price,
                'priced_by' => $request->user()->id,
            ]);

            $updated++;
        }

        if ($updated === 0) {
            return back()->with('error', 'Ни одной цены не установлено: у выбранных партий нет справочной цены или они закрыты.');
        }

        $message = "Цена установлена: {$updated} ".$this->plural($updated, 'партия', 'партии', 'партий').'.';

        if ($skippedNoPrice > 0) {
            $message .= " Пропущено без справочной цены: {$skippedNoPrice}.";
        }

        if ($skippedClosed > 0) {
            $message .= " Пропущено закрытых: {$skippedClosed}.";
        }

        return back()->with('success', $message);
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
     * Русское склонение по числу: 1 партия, 2 партии, 5 партий.
     */
    private function plural(int $count, string $one, string $few, string $many): string
    {
        $mod100 = $count % 100;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return $many;
        }

        return match ($count % 10) {
            1 => $one,
            2, 3, 4 => $few,
            default => $many,
        };
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
     * @param  array<int, array<string, mixed>>  $referenceMap
     * @param  array<string, array{stock: int, covered: int}>  $stockMap
     * @return array<string, mixed>
     */
    private function presentDefect(ProductDefect $defect, array $availableMap, array $referenceMap = [], array $stockMap = []): array
    {
        $stock = $this->defectStockRow($defect, $stockMap);

        return [
            'id' => $defect->id,
            'defect_description' => $defect->defect_description,
            'quantity' => $defect->quantity,
            'available_quantity' => $availableMap[$defect->id] ?? 0,
            'reserved_quantity' => $defect->quantity - ($availableMap[$defect->id] ?? 0),
            // Что числится по товару на складе некондиции в 1С: остаток целиком,
            // сколько из него разобрано открытыми партиями и сколько осталось.
            'erp_stock_quantity' => $stock['stock'],
            'covered_quantity' => $stock['covered'],
            'uncovered_quantity' => $stock['stock'] - $stock['covered'],
            'price' => $defect->price !== null ? (float) $defect->price : null,
            'is_published' => $defect->is_published,
            'closed_at' => $defect->closed_at?->toIso8601String(),
            'closed_reason_label' => $defect->closed_reason?->label(),
            'created_by_name' => $defect->creator?->name,
            'priced_by_name' => $defect->pricer?->name,
            'created_at' => $defect->created_at?->toIso8601String(),
            'reference_price' => $referenceMap[$defect->product_id] ?? null,
            'product' => [
                'id' => $defect->product->id,
                'name' => $defect->product->name,
                'sku' => $defect->product->sku,
                'code' => $defect->product->code,
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

    /**
     * Строка выгрузки: те же величины, что на экране, плюс реквизиты товара,
     * по которым сверяются с 1С (код и артикул).
     *
     * @param  array<int, int>  $availableMap
     * @param  array<int, array<string, mixed>>  $referenceMap
     * @param  array<string, array{stock: int, covered: int}>  $stockMap
     * @return array<int, string|int|float|null>
     */
    private function exportRow(ProductDefect $defect, array $availableMap, array $referenceMap, array $stockMap): array
    {
        $stock = $this->defectStockRow($defect, $stockMap);
        $available = $availableMap[$defect->id] ?? 0;
        $price = $defect->price !== null ? (float) $defect->price : null;
        $reference = $referenceMap[$defect->product_id] ?? null;
        $referencePrice = $reference['price'] ?? null;

        return [
            $defect->id,
            $defect->product->code,
            $defect->product->sku,
            $defect->product->name,
            $defect->warehouse->name,
            $defect->defect_description,
            $stock['stock'],
            $stock['covered'],
            $stock['stock'] - $stock['covered'],
            (int) $defect->quantity,
            (int) $defect->quantity - $available,
            $available,
            $referencePrice,
            $reference['status']['name'] ?? null,
            $price,
            // Насколько уценка ниже цены клиента — главный вопрос к строке
            // при разборе выгрузки, считать его в Excel вручную незачем.
            $referencePrice > 0 && $price !== null
                ? round((1 - $price / $referencePrice) * 100, 1)
                : null,
            $defect->is_published ? 'да' : 'нет',
            $defect->isClosed() ? 'закрыта' : 'открыта',
            $defect->creator?->name,
            $defect->created_at?->format('d.m.Y H:i'),
            $defect->pricer?->name,
            $defect->closed_at?->format('d.m.Y H:i'),
            $defect->closed_reason?->label(),
        ];
    }

    /**
     * Остаток 1С и покрытие партиями для пары товар + склад этой партии.
     *
     * @param  array<string, array{stock: int, covered: int}>  $stockMap
     * @return array{stock: int, covered: int}
     */
    private function defectStockRow(ProductDefect $defect, array $stockMap): array
    {
        $key = DefectCoverageService::pairKey((int) $defect->product_id, (int) $defect->warehouse_id);

        return $stockMap[$key] ?? ['stock' => 0, 'covered' => 0];
    }
}
