<?php

namespace App\Mcp\Tools\Crm;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Ярлык: найти человека по адресу, телефону или имени.
 *
 * Самый частый вопрос при разборе почты — «чей это адрес». Платить за него
 * тремя вызовами (каталог → схема → вызов) разговор не выдерживает.
 *
 * Под капотом те же операции реестра: `contact.by_email` для точного адреса
 * и `contact.list` для поиска по строке.
 */
#[IsReadOnly]
class CrmContactLookup extends Tool
{
    use InteractsWithCrmOperations;

    protected string $name = 'crm-contact-lookup';

    protected string $description = 'Найти контактное лицо по адресу почты, телефону или имени. '
        .'По точному адресу отдаёт и человека, и партнёра — этого достаточно, чтобы подшить письмо. '
        .'Контакты вне зоны ответственности сотрудника недоступны.';

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('Точный адрес почты. Если указан, поиск идёт по нему и отдаёт заодно партнёра.'),
            'search' => $schema->string()
                ->description('ФИО, телефон или должность. Телефон ищется по цифрам: «8 912…» и «+7 912…» — один номер.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $email = trim((string) $request->get('email'));

        if ($email !== '') {
            return $this->execute('contact.by_email', ['email' => $email]);
        }

        return $this->execute('contact.list', [
            'search' => (string) $request->get('search'),
            'per_page' => 20,
        ]);
    }
}
