<?php

namespace App\Http\Controllers\Api\Client;

use App\Exceptions\DebtRestrictionException;
use App\Exceptions\InsufficientStockException;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\Client\Api\CompanyRequired;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\GateClosed;
use App\Services\Client\Api\Idempotency\IdempotencyConflict;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationRegistry;
use App\Services\Client\Api\OperationRunner;
use App\Services\Order\NothingToCheckoutException;
use App\Services\Order\NothingToPlaceException;
use App\Services\Order\ReserveActionException;
use App\Support\OperationApi\OperationDenied;
use App\Support\Preorder\PreorderTerms;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * REST-вход клиентского API v1: `/api/client/v1/*`.
 *
 * Контроллер один на все операции. Маршруты собираются обходом
 * {@see OperationRegistry}, поэтому добавление операции — одна запись в секции
 * реестра, а не «контроллер + маршрут + строка в discovery + инструмент MCP».
 *
 * @tags Client
 */
class ClientApiController extends Controller
{
    /** Префикс имён маршрутов; по хвосту имени находится операция. */
    public const ROUTE_PREFIX = 'api.client.v1.';

    /** Заголовок ключа идемпотентности. */
    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly OperationRunner $runner,
    ) {}

    /**
     * Кто я и что мне доступно.
     *
     * Ответ строится из реестра и не может разойтись с тем, что сервер принимает.
     * Флаг `allowed` учитывает разделы кабинета, закрытые клиенту флагами.
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $operations = [];

        foreach ($this->registry->all() as $operation) {
            $operations[] = $operation->catalogEntry($actor) + ['schema' => $operation->jsonSchema()];
        }

        return response()->json(Envelope::data([
            'actor' => [
                'id' => (int) $actor->getKey(),
                'name' => $actor->name,
                'email' => $actor->email,
            ],
            'companies' => $actor->companies()
                ->orderByDesc('is_default')
                ->orderBy('id')
                ->get()
                ->map(fn (Company $company): array => [
                    'id' => (int) $company->getKey(),
                    'name' => $company->name,
                    'legal_name' => $company->legal_name,
                    'inn' => $company->tax_id,
                    'is_default' => (bool) $company->is_default,
                ])
                ->values()
                ->all(),
            'features' => $this->features($actor),
            'sections' => $this->registry->sections(),
            'operations' => $operations,
            'docs' => [
                'ui' => url('/docs/client-api'),
                'openapi' => url('/docs/client-api.json'),
                'mcp' => url('/mcp/client'),
            ],
            'limits' => [
                'requests_per_minute' => 60,
                'per_page_max' => Envelope::PER_PAGE_MAX,
                'per_page_max_feed' => Envelope::PER_PAGE_MAX_FEED,
                'idempotency_header' => self::IDEMPOTENCY_HEADER,
            ],
        ]));
    }

    /**
     * Выполнить операцию, к маршруту которой пришёл запрос.
     */
    public function run(Request $request): JsonResponse
    {
        $operation = $this->operation($request);
        $actor = $this->actor($request);

        // Параметры пути кладём последними: адрес надёжнее тела, и `orders/5/cancel`
        // с `order=9` в теле должен остаться операцией по заказу 5.
        $args = array_merge($request->all(), $request->route()?->parameters() ?? []);
        $key = $request->header(self::IDEMPOTENCY_HEADER);
        $key = is_string($key) && trim($key) !== '' ? trim($key) : null;

        try {
            $result = $this->runner->run($operation, $actor, $args, $key);
        } catch (GateClosed $e) {
            return $this->error($e->code(), $e->getMessage(), 403);
        } catch (OperationDenied $e) {
            return $this->error('operation_denied', $e->getMessage(), 403);
        } catch (CompanyRequired $e) {
            return response()->json(Envelope::error('company_required', $e->getMessage(), 'company_id', [
                'companies' => $e->choices(),
            ]), 422);
        } catch (ValidationException $e) {
            return response()->json(Envelope::validation($e->errors()), 422);
        } catch (IdempotencyConflict $e) {
            return response()->json(Envelope::error($e->errorCode, $e->getMessage(), null, $e->meta), $e->status);
        } catch (ModelNotFoundException) {
            return $this->error('not_found', 'Запись не найдена или недоступна этому клиенту.', 404);
        } catch (NothingToCheckoutException $e) {
            return $this->error('nothing_to_checkout', $e->getMessage(), 422);
        } catch (NothingToPlaceException $e) {
            return response()->json(Envelope::error('nothing_to_place', $e->getMessage(), 'products', [
                'not_accepted' => $e->notAccepted,
            ]), 422);
        } catch (ReserveActionException $e) {
            // Коды legacy сохраняются: stale_items_version (409), not_cancellable и т. д.
            return $this->error($e->errorCode, $e->getMessage(), $e->status);
        } catch (\App\Services\Pickup\PickupPassException $e) {
            return $this->error($e->reason, $e->getMessage(), 422);
        } catch (InsufficientStockException $e) {
            return response()->json(Envelope::error('stock_changed', $e->getMessage(), null, [
                'conflicts' => $e->getItems(),
            ]), 409);
        } catch (\App\Exceptions\ProductWithoutPriceException $e) {
            // Товар без цены: ни базовой в карточке, ни индивидуальной от 1С.
            // Агенту нужно знать, какие строки убрать, а не получить 500.
            return response()->json(Envelope::error('no_price', $e->getMessage(), 'items', [
                'items' => $e->getItems(),
            ]), 422);
        } catch (DebtRestrictionException $e) {
            return response()->json(Envelope::error('debt_restricted', $e->getMessage(), null, [
                'debt' => $e->toPayload(),
            ]), 422);
        } catch (HttpException $e) {
            // abort_if/abort_unless внутри сервисов кабинета (например, «Доступно к
            // возврату: N») — переводим в конверт, код по статусу.
            $status = $e->getStatusCode();
            $code = match ($status) {
                403 => 'forbidden',
                404 => 'not_found',
                422 => 'business_rule',
                default => 'http_'.$status,
            };

            return $this->error($code, $e->getMessage() !== '' ? $e->getMessage() : 'Запрос отклонён.', $status);
        } catch (InvalidArgumentException|RuntimeException|LogicException $e) {
            // Отказ бизнес-правила — не 500: агенту нужно понять, что делать дальше.
            return $this->error('business_rule', $e->getMessage(), 422);
        }

        $status = $operation->mutating && $operation->method === 'POST' && ($result['meta']['created'] ?? false) ? 201 : 200;

        return response()->json($result, $status);
    }

    private function operation(Request $request): Operation
    {
        $name = (string) $request->route()?->getName();
        $operation = $this->registry->find(Str::after($name, self::ROUTE_PREFIX));

        abort_if($operation === null, 404, 'Операция не найдена.');

        return $operation;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        abort_if($actor === null, 401, 'Требуется токен.');

        return $actor;
    }

    /**
     * Состояние разделов кабинета для этого клиента: агент видит, что выключено,
     * до первого вызова, и не тратит запросы на заведомые 403.
     *
     * @return array<string, mixed>
     */
    private function features(User $actor): array
    {
        $features = [];

        foreach (FeatureGate::cases() as $gate) {
            if ($gate === FeatureGate::NONE) {
                continue;
            }

            $features[$gate->value] = $gate->allows($actor);
        }

        $features['preorders_enabled'] = $actor->preordersEnabled();
        $features['preorder_lead_days'] = PreorderTerms::leadDays();

        return $features;
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(Envelope::error($code, $message), $status);
    }
}
