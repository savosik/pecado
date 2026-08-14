<?php

namespace App\Mcp\Tools\Purchasing;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Models\ProductDefect;
use App\Services\Defect\DefectReferencePriceService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Список партий уценки со всем, что нужно для оценки: фото дефектов,
 * остатки, справочные цены по статусам клиентов.
 */
#[IsReadOnly]
class DefectBatches extends Tool
{
    use InteractsWithDefectBatches;

    protected string $name = 'defect-batches';

    protected string $description = 'Список партий уценки (некондиции) с фотографиями дефектов, остатками, '
        .'текущей ценой и справочными ценами по статусам клиентов. Фильтры: open (по умолчанию) — все открытые, '
        .'unpriced — ждут цену, unpublished — цена есть, но не опубликованы, published — в продаже, closed — закрытые.';

    public function __construct(
        private readonly DefectStockServiceInterface $defectStock,
        private readonly DefectReferencePriceService $referencePrices,
    ) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'filter' => $schema->string()
                ->description('Фильтр: open | unpriced | unpublished | published | closed. По умолчанию open.'),
            'search' => $schema->string()
                ->description('Поиск по описанию дефекта, названию или артикулу товара.'),
            'page' => $schema->integer()
                ->description('Номер страницы, с 1. По умолчанию 1.'),
            'per_page' => $schema->integer()
                ->description('Партий на страницу, 1–50. По умолчанию 20.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $filter = trim((string) $request->get('filter', 'open')) ?: 'open';

        $allowed = ['open', 'unpriced', 'unpublished', 'published', 'closed'];

        if (! in_array($filter, $allowed, true)) {
            return Response::error('Неизвестный фильтр «'.$filter.'». Доступны: '.implode(', ', $allowed).'.');
        }

        $perPage = min(50, max(1, (int) $request->get('per_page', 20)));
        $page = max(1, (int) $request->get('page', 1));

        $defects = ProductDefect::query()
            ->with(['product:id,name,sku', 'warehouse:id,name', 'media'])
            ->when($request->get('search'), function ($query, $search) {
                $search = trim((string) $search);

                $query->where(function ($q) use ($search) {
                    $q->where('defect_description', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                        );
                });
            })
            ->when($filter === 'open', fn ($q) => $q->whereNull('closed_at'))
            ->when($filter === 'unpriced', fn ($q) => $q->whereNull('closed_at')->whereNull('price'))
            ->when($filter === 'unpublished', fn ($q) => $q->whereNull('closed_at')->whereNotNull('price')->where('is_published', false))
            ->when($filter === 'published', fn ($q) => $q->whereNull('closed_at')->where('is_published', true))
            ->when($filter === 'closed', fn ($q) => $q->whereNotNull('closed_at'))
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $available = $this->defectStock->availableMap($defects->getCollection());
        $reference = $this->referencePrices->forProducts(
            $defects->getCollection()->pluck('product_id')->all()
        );

        return $this->payload([
            'total' => $defects->total(),
            'page' => $defects->currentPage(),
            'last_page' => $defects->lastPage(),
            'filter' => $filter,
            'batches' => $defects->getCollection()
                ->map(fn (ProductDefect $defect) => $this->present($defect, $available, $reference))
                ->all(),
        ]);
    }
}
