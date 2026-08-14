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
 * Одна партия уценки целиком: фото в полном размере, полная лестница
 * справочных цен по статусам клиентов и остатки.
 */
#[IsReadOnly]
class DefectBatch extends Tool
{
    use InteractsWithDefectBatches;

    protected string $name = 'defect-batch';

    protected string $description = 'Карточка одной партии уценки: фотографии дефекта (полный размер и превью), '
        .'остаток за вычетом резерва, текущая цена, публикация и лестница справочных цен по статусам клиентов.';

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
            'id' => $schema->integer()
                ->description('Идентификатор партии из defect-batches.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $defect = ProductDefect::query()
            ->with(['product:id,name,sku', 'warehouse:id,name', 'media'])
            ->find((int) $request->get('id'));

        if (! $defect) {
            return Response::error('Партия не найдена. Список партий — в defect-batches.');
        }

        $available = $this->defectStock->availableMap([$defect]);
        $reference = $this->referencePrices->forProducts([$defect->product_id]);

        return $this->payload($this->present($defect, $available, $reference));
    }
}
