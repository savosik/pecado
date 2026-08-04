<?php

namespace App\Mcp\Tools\Crm;

use App\Models\User;
use App\Services\Crm\Api\Operation;
use App\Services\Crm\Api\OperationDenied;
use App\Services\Crm\Api\OperationRegistry;
use App\Services\Crm\Api\OperationRunner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Response;
use RuntimeException;

/**
 * Общая часть инструментов CRM-сервера: актор и выполнение операции.
 *
 * Инструменты не делают работу сами — они вызывают ту же операцию реестра, что
 * и REST. Отказы переводятся в текст: агент должен понять, что делать дальше,
 * а «500 Internal Server Error» ему ничего не сообщает.
 */
trait InteractsWithCrmOperations
{
    protected function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * JSON-ответ с читаемой кириллицей.
     *
     * Штатный `Response::json()` кодирует без JSON_UNESCAPED_UNICODE, и весь
     * русский текст уезжает агенту в виде `Заказ`.
     * Клиент это разберёт, но заплатит втрое больше токенов за те же слова,
     * а человек, читающий лог обмена, не разберёт вовсе. Payload у нас
     * русский почти целиком, поэтому кодируем сами.
     *
     * @param  array<string, mixed>  $data
     */
    protected function payload(array $data): Response
    {
        return Response::text(json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * Выполнить операцию и превратить любой отказ в понятный агенту ответ.
     *
     * @param  array<string, mixed>  $args
     */
    protected function execute(string $operationId, array $args): Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить сотрудника по токену. Обратитесь к руководителю отдела.');
        }

        $operation = app(OperationRegistry::class)->find($operationId);

        if (! $operation instanceof Operation) {
            return Response::error(
                "Операции «{$operationId}» нет. Полный список — в crm-catalog."
            );
        }

        try {
            $result = app(OperationRunner::class)->run($operation, $actor, $args);
        } catch (OperationDenied $e) {
            return Response::error($e->getMessage());
        } catch (ValidationException $e) {
            $messages = [];

            foreach ($e->errors() as $field => $errors) {
                $messages[] = $field.': '.implode(' ', $errors);
            }

            return Response::error(
                'Аргументы не приняты. '.implode('; ', $messages)
                ."\nСхема аргументов — в crm-describe для операции «{$operationId}»."
            );
        } catch (ModelNotFoundException) {
            // Не «нет доступа»: запись вне скоупа менеджера для агента просто
            // не существует — так же, как и в вебе.
            return Response::error('Запись не найдена или недоступна этому сотруднику.');
        } catch (InvalidArgumentException|RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return $this->payload($result);
    }
}
