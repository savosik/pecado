<?php

namespace App\Mcp\Tools\Crm;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: выполнение плана за месяц.
 *
 * «Как у нас с планом» — вопрос, который задают каждый день, и он не должен
 * стоить трёх вызовов.
 */
#[IsReadOnly]
class CrmPlanProgress extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-plan-progress';

    protected string $description = 'Выполнение плана продаж за месяц: план, факт, отставание '
        .'и разбивка по клиентам. Факт считается по дате документа в 1С.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()
                ->description('Месяц в формате ГГГГ-ММ. Пусто — текущий.'),
            'limit' => $schema->integer()
                ->description('Сколько клиентов вернуть в разбивке (по умолчанию 50).'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->execute('plan.progress', array_filter([
            'month' => $request->get('month'),
            'limit' => $request->get('limit'),
        ], fn ($value) => $value !== null && $value !== ''));
    }
}
