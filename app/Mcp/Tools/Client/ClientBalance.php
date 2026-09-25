<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: сколько я должен и когда платить.
 */
#[IsReadOnly]
class ClientBalance extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-balance';

    protected string $description = 'Баланс взаиморасчётов (долг, просрочка, по организациям) и календарь оплат текущего '
        .'месяца по регистру 1С. Доступно, когда клиенту открыт раздел «Оплаты»; иначе — обратитесь к менеджеру.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->description('Месяц календаря YYYY-MM; по умолчанию текущий.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $balance = $this->run('finance.balance', []);

        if ($balance instanceof Response) {
            return $balance;
        }

        $calendar = $this->run('finance.calendar', array_filter(['month' => $request->get('month')]));

        if ($calendar instanceof Response) {
            return $calendar;
        }

        return $this->payload([
            'balance' => $balance['data'] ?? null,
            'calendar' => $calendar['data'] ?? null,
        ]);
    }
}
