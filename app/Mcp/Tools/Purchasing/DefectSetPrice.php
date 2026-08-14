<?php

namespace App\Mcp\Tools\Purchasing;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Models\ProductDefect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Назначить цену партии уценки.
 *
 * Правила те же, что у закупщика в /admin/defects (Admin\DefectController::updatePrice):
 * закрытую партию переоценить нельзя, автор цены фиксируется в priced_by.
 */
class DefectSetPrice extends Tool
{
    use InteractsWithDefectBatches;

    /** Потолок цены — как в UpdateDefectPriceRequest (decimal(10,2) в БД). */
    private const MAX_PRICE = 9999999.99;

    protected string $name = 'defect-set-price';

    protected string $description = 'Назначить цену партии уценки (за штуку, в рублях). Цена видна клиентам '
        .'после публикации партии. Ориентир — лестница справочных цен по статусам клиентов из defect-batches: '
        .'цена уценки обычно ниже цены лучшего статуса. Операция необратима в том смысле, что старая цена не сохраняется.';

    public function __construct(private readonly DefectStockServiceInterface $defectStock) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->description('Идентификатор партии из defect-batches.')
                ->required(),
            'price' => $schema->number()
                ->description('Цена за штуку в рублях, больше нуля, до двух знаков после запятой.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить закупщика по токену.');
        }

        if (! $actor->can('defects.price')) {
            return Response::error('Нет права «defects.price»: назначать цены этому сотруднику нельзя.');
        }

        $defect = ProductDefect::query()->with(['product:id,name,sku', 'warehouse:id,name'])->find((int) $request->get('id'));

        if (! $defect) {
            return Response::error('Партия не найдена. Список партий — в defect-batches.');
        }

        if ($defect->isClosed()) {
            return Response::error('Партия закрыта — цену изменить нельзя.');
        }

        $price = round((float) $request->get('price'), 2);

        if ($price <= 0 || $price > self::MAX_PRICE) {
            return Response::error('Цена должна быть больше нуля и не выше '.self::MAX_PRICE.'.');
        }

        $defect->update([
            'price' => $price,
            'priced_by' => $actor->id,
        ]);

        $this->audit('defect.set-price', ['defect_id' => $defect->id, 'price' => $price]);

        return $this->payload([
            'message' => 'Цена сохранена.',
            'batch' => $this->present($defect, $this->defectStock->availableMap([$defect])),
        ]);
    }
}
