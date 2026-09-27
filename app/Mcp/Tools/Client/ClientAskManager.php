<?php

namespace App\Mcp\Tools\Client;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Ярлык: вопрос менеджеру — для всего, что API не решает.
 */
class ClientAskManager extends Tool
{
    use InteractsWithClientOperations;

    protected string $name = 'client-ask-manager';

    protected string $description = 'Задать вопрос персональному менеджеру: объединить заказы в одну отправку, подождать предзаказ, '
        .'самовывоз к определённому часу, спорная строка в сверке — всё, чего нет в операциях. Ответ обычно в течение '
        .'рабочего дня, читается операцией questions.get. Не выдумывайте ответ за менеджера.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->description('Тема (3–200 символов).')->required(),
            'body' => $schema->string()->description('Текст вопроса (10–5000 символов); укажите номера заказов или документов.')->required(),
            'idempotency_key' => $schema->string()->description('Ключ, чтобы повтор не создал второй вопрос.'),
        ];
    }

    public function handle(Request $request): Response
    {
        return $this->execute('questions.create', [
            'subject' => (string) $request->get('subject'),
            'body' => (string) $request->get('body'),
        ], $request->get('idempotency_key'));
    }
}
