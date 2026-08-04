<?php

namespace App\Mcp\Tools\Crm;

use App\Services\Crm\Api\OperationRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Что вообще умеет CRM и что из этого доступно текущему сотруднику.
 *
 * Каталог вместо десятков отдельных инструментов: операций уже за тридцать и
 * станет больше, и выложенные по одному они превратили бы список инструментов
 * в нечитаемое полотно. Пара «каталог → вызов» повторяет привычный агентам
 * порядок list-tables → describe-table → run-query с аналитического сервера.
 */
#[IsReadOnly]
class CrmCatalog extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-catalog';

    protected string $description = 'Список операций CRM с назначением и доступностью. '
        .'Начинать работу с него: флаг allowed сразу показывает, что доступно этому сотруднику, '
        .'и избавляет от вызовов, которые заведомо вернут отказ.';

    public function __construct(private readonly OperationRegistry $registry) {}

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'section' => $schema->string()
                ->description('Раздел: clients, profile, comments, tasks, calls, emails, plans, opportunities, attachments. Пусто — все.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить сотрудника по токену.');
        }

        $section = trim((string) $request->get('section', ''));

        return $this->payload([
            'actor' => $actor->name,
            'sections' => $this->registry->sections(),
            'operations' => $this->registry->catalog($actor, $section === '' ? null : $section),
            'hint' => 'Схема аргументов операции — в crm-describe, выполнение — в crm-call.',
        ]);
    }
}
