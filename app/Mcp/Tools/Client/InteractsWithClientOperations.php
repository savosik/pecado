<?php

namespace App\Mcp\Tools\Client;

use App\Exceptions\DebtRestrictionException;
use App\Exceptions\InsufficientStockException;
use App\Models\User;
use App\Services\Client\Api\CompanyRequired;
use App\Services\Client\Api\GateClosed;
use App\Services\Client\Api\Idempotency\IdempotencyConflict;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationRegistry;
use App\Services\Client\Api\OperationRunner;
use App\Services\Order\NothingToPlaceException;
use App\Services\Order\ReserveActionException;
use App\Support\OperationApi\OperationDenied;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Response;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Общая часть инструментов клиентского MCP-сервера: актор и выполнение операции.
 *
 * Инструменты не делают работу сами — они вызывают ту же операцию реестра, что
 * и REST v1, через тот же OperationRunner (гейты, валидация, юрлицо,
 * идемпотентность, аудит). Отказы переводятся в текст с подсказкой, что делать.
 */
trait InteractsWithClientOperations
{
    protected function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    /**
     * JSON-ответ с читаемой кириллицей: штатный Response::json() экранирует
     * русский текст, и агент платит втрое больше токенов за те же слова.
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
    protected function execute(string $operationId, array $args, ?string $idempotencyKey = null): Response
    {
        $result = $this->run($operationId, $args, $idempotencyKey);

        return $result instanceof Response ? $result : $this->payload($result);
    }

    /**
     * Выполнить операцию: массив результата либо готовый ответ-отказ.
     *
     * Ярлыки, склеивающие несколько операций, работают с массивом, а не
     * разбирают текст собственного ответа.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>|Response
     */
    protected function run(string $operationId, array $args, ?string $idempotencyKey = null): array|Response
    {
        $actor = $this->actor();

        if ($actor === null) {
            return Response::error('Не удалось определить клиента по токену. Проверьте ключ на странице /api-tokens в кабинете.');
        }

        $operation = app(OperationRegistry::class)->find($operationId);

        if (! $operation instanceof Operation) {
            return Response::error("Операции «{$operationId}» нет. Полный список — в client-catalog.");
        }

        $key = is_string($idempotencyKey) && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        try {
            $result = app(OperationRunner::class)->run($operation, $actor, $args, $key);
        } catch (GateClosed $e) {
            return Response::error("[{$e->code()}] {$e->getMessage()} Раздел отмечен allowed=false в client-catalog — не повторяйте вызов.");
        } catch (OperationDenied $e) {
            return Response::error($e->getMessage());
        } catch (CompanyRequired $e) {
            return Response::error("[company_required] {$e->getMessage()} Спросите у человека, от какого юрлица работать, и передайте company_id. Варианты: "
                .json_encode($e->choices(), JSON_UNESCAPED_UNICODE));
        } catch (IdempotencyConflict $e) {
            return Response::error("[{$e->errorCode}] {$e->getMessage()}");
        } catch (ValidationException $e) {
            $messages = [];

            foreach ($e->errors() as $field => $errors) {
                $messages[] = $field.': '.implode(' ', $errors);
            }

            return Response::error('Аргументы не приняты. '.implode('; ', $messages)
                ."\nСхема аргументов — в client-describe для операции «{$operationId}».");
        } catch (ModelNotFoundException) {
            return Response::error('[not_found] Запись не найдена или недоступна этому клиенту.');
        } catch (NothingToPlaceException $e) {
            return Response::error('[nothing_to_place] '.$e->getMessage().' Причины: '.json_encode($e->notAccepted, JSON_UNESCAPED_UNICODE));
        } catch (ReserveActionException $e) {
            return Response::error("[{$e->errorCode}] {$e->getMessage()}");
        } catch (InsufficientStockException $e) {
            return Response::error('[stock_changed] '.$e->getMessage().' Выполните checkout.normalize и повторите. Конфликты: '
                .json_encode($e->getItems(), JSON_UNESCAPED_UNICODE));
        } catch (DebtRestrictionException $e) {
            return Response::error('[debt_restricted] '.$e->getMessage().' Ограничение по долгу снимает менеджер после оплаты.');
        } catch (HttpException $e) {
            return Response::error($e->getMessage() !== '' ? $e->getMessage() : 'Запрос отклонён.');
        } catch (InvalidArgumentException|RuntimeException|LogicException $e) {
            return Response::error($e->getMessage());
        }

        return $result;
    }
}
