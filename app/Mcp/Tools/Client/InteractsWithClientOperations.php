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
use App\Services\Client\Api\Usage\UsageContext;
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
     * Чату-помощнику (токен вида assistant) — компактный JSON без служебных
     * полей и пустых значений: отступы и uuid давали до 40 % объёма ответа.
     *
     * @param  array<string, mixed>  $data
     */
    protected function payload(array $data): Response
    {
        if (\App\Support\Client\ClientApiSource::isAssistant()) {
            return Response::text(json_encode(
                \App\Support\Client\AssistantPayload::slim($data),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }

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
            return $this->refuse('unauthorized', 'Не удалось определить клиента по токену. Проверьте ключ на странице /api-tokens в кабинете.');
        }

        $operation = app(OperationRegistry::class)->find($operationId);

        if (! $operation instanceof Operation) {
            return $this->refuse('unknown_operation', "Операции «{$operationId}» нет. Полный список — в client-catalog.");
        }

        $key = is_string($idempotencyKey) && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        // Чат-помощник: страницы не больше 25 строк — модель попросит следующую,
        // если клиенту нужно больше; ярлыки просят по 100, это для CLI-агентов.
        if (\App\Support\Client\ClientApiSource::isAssistant() && isset($args['per_page']) && (int) $args['per_page'] > \App\Support\Client\AssistantPayload::PER_PAGE) {
            $args['per_page'] = \App\Support\Client\AssistantPayload::PER_PAGE;
        }

        try {
            $result = app(OperationRunner::class)->run($operation, $actor, $args, $key);
        } catch (GateClosed $e) {
            return $this->refuse($e->code(), "[{$e->code()}] {$e->getMessage()} Раздел отмечен allowed=false в client-catalog — не повторяйте вызов.");
        } catch (OperationDenied $e) {
            return $this->refuse('operation_denied', $e->getMessage());
        } catch (\App\Services\Assistant\Confirmations\ConfirmationRequired $e) {
            // Чат-помощник: карточка подтверждения уже показана клиенту.
            return $this->refuse('confirmation_required', '[confirmation_required] '.$e->getMessage());
        } catch (CompanyRequired $e) {
            return $this->refuse('company_required', "[company_required] {$e->getMessage()} Спросите у человека, от какого юрлица работать, и передайте company_id. Варианты: "
                .json_encode($e->choices(), JSON_UNESCAPED_UNICODE));
        } catch (IdempotencyConflict $e) {
            // В REST ключ — заголовок Idempotency-Key, а в MCP — аргумент инструмента:
            // агенту нужна подсказка на его языке, иначе он ищет, куда передать заголовок.
            $message = $e->errorCode === 'idempotency_key_required'
                ? 'Для этой операции обязателен аргумент idempotency_key (например, UUID): повтор с тем же ключом не создаст дубль.'
                : $e->getMessage();

            return $this->refuse($e->errorCode, "[{$e->errorCode}] {$message}");
        } catch (ValidationException $e) {
            $messages = [];

            foreach ($e->errors() as $field => $errors) {
                $messages[] = $field.': '.implode(' ', $errors);
            }

            return $this->refuse('validation', 'Аргументы не приняты. '.implode('; ', $messages)
                ."\nСхема аргументов — в client-describe для операции «{$operationId}».");
        } catch (ModelNotFoundException) {
            return $this->refuse('not_found', '[not_found] Запись не найдена или недоступна этому клиенту.');
        } catch (NothingToPlaceException $e) {
            return $this->refuse('nothing_to_place', '[nothing_to_place] '.$e->getMessage().' Причины: '.json_encode($e->notAccepted, JSON_UNESCAPED_UNICODE));
        } catch (ReserveActionException $e) {
            return $this->refuse($e->errorCode, "[{$e->errorCode}] {$e->getMessage()}");
        } catch (\App\Services\Pickup\PickupPassException $e) {
            return $this->refuse($e->reason, "[{$e->reason}] {$e->getMessage()}");
        } catch (InsufficientStockException $e) {
            return $this->refuse('stock_changed', '[stock_changed] '.$e->getMessage().' Выполните checkout.normalize и повторите. Конфликты: '
                .json_encode($e->getItems(), JSON_UNESCAPED_UNICODE));
        } catch (DebtRestrictionException $e) {
            return $this->refuse('debt_restricted', '[debt_restricted] '.$e->getMessage().' Ограничение по долгу снимает менеджер после оплаты.');
        } catch (HttpException $e) {
            return $this->refuse('http_'.$e->getStatusCode(), $e->getMessage() !== '' ? $e->getMessage() : 'Запрос отклонён.');
        } catch (InvalidArgumentException|RuntimeException|LogicException $e) {
            return $this->refuse('rejected', $e->getMessage());
        }

        return $result;
    }

    /**
     * Отказ агенту с кодом для журнала вызовов: текст — агенту, код — в
     * `client_agent_calls.error_code`, чтобы частые отказы было видно на экране
     * «ИИ-агенты клиентов», а не только в тексте ответов.
     */
    protected function refuse(string $code, string $message): Response
    {
        app(UsageContext::class)->fail($code);

        return Response::error($message);
    }
}
