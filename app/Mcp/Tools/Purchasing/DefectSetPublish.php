<?php

namespace App\Mcp\Tools\Purchasing;

use App\Contracts\Defect\DefectStockServiceInterface;
use App\Models\ProductDefect;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Включить или выключить публикацию партии уценки на сайте.
 *
 * Правила те же, что у закупщика в /admin/defects (Admin\DefectController::togglePublish):
 * публиковать без цены нельзя — иначе на витрине окажется товар, который
 * нельзя купить; закрытую партию трогать нельзя.
 */
class DefectSetPublish extends Tool
{
    use InteractsWithDefectBatches;

    protected string $name = 'defect-set-publish';

    protected string $description = 'Включить или выключить видимость партии уценки на сайте («галочка активности акции»). '
        .'Опубликованная партия сразу видна клиентам и доступна к заказу. Публиковать партию без цены нельзя — '
        .'сначала назначьте цену через defect-set-price.';

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
            'is_published' => $schema->boolean()
                ->description('true — опубликовать на сайте, false — снять с публикации.')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить закупщика по токену.');
        }

        if (! $actor->can('defects.publish')) {
            return Response::error('Нет права «defects.publish»: управлять публикацией этому сотруднику нельзя.');
        }

        $defect = ProductDefect::query()->with(['product:id,name,sku', 'warehouse:id,name'])->find((int) $request->get('id'));

        if (! $defect) {
            return Response::error('Партия не найдена. Список партий — в defect-batches.');
        }

        if ($defect->isClosed()) {
            return Response::error('Партия закрыта — публикацию изменить нельзя.');
        }

        $publish = (bool) $request->get('is_published');

        if ($publish && ! $defect->canBePublished()) {
            return Response::error('Нельзя опубликовать партию без цены. Сначала назначьте цену через defect-set-price.');
        }

        $defect->update(['is_published' => $publish]);

        $this->audit('defect.set-publish', ['defect_id' => $defect->id, 'is_published' => $publish]);

        return $this->payload([
            'message' => $publish ? 'Партия опубликована на сайте.' : 'Партия снята с публикации.',
            'batch' => $this->present($defect, $this->defectStock->availableMap([$defect])),
        ]);
    }
}
