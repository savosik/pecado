<?php

namespace App\Http\Controllers\Api\Crm;

use App\Enums\Crm\CrmScope;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Crm\Api\Operation;
use App\Services\Crm\Api\OperationDenied;
use App\Services\Crm\Api\OperationRegistry;
use App\Services\Crm\Api\OperationRunner;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * REST-вход агентского гейта: `/api/crm/*`.
 *
 * Контроллер намеренно один на все операции. Маршруты собираются обходом
 * {@see OperationRegistry}, поэтому добавление операции — одна запись в реестре,
 * а не «контроллер + маршрут + строка в discovery + строка в каталоге MCP»,
 * четыре места, из которых однажды обновят три.
 *
 * @tags CRM
 */
class CrmApiController extends Controller
{
    /** Префикс имён маршрутов; по хвосту имени находится операция. */
    public const ROUTE_PREFIX = 'api.crm.';

    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly OperationRunner $runner,
    ) {}

    /**
     * Кто я и что мне доступно.
     *
     * Ответ строится из реестра, поэтому список операций не может разойтись
     * с тем, что сервер реально принимает. Флаг `allowed` считается по правам
     * актора — агент сразу видит свой набор и не тратит вызовы на заведомые 403.
     */
    public function me(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        $operations = [];

        foreach ($this->registry->all() as $operation) {
            $operations[] = $operation->catalogEntry($actor) + ['schema' => $operation->jsonSchema()];
        }

        return response()->json([
            'data' => [
                'actor' => [
                    'id' => (int) $actor->getKey(),
                    'name' => $actor->name,
                    'email' => $actor->email,
                    'is_head' => $actor->can('crm-clients-all.view'),
                    'manager_profile' => $actor->managerProfile?->name,
                ],
                'permissions' => $this->permissions($actor),
                'scope' => [
                    // Считаем по разрезу по умолчанию, а не по максимуму: агент
                    // без параметра `scope` получит именно столько партнёров,
                    // и цифра в discovery не должна обещать больше, чем отдадут.
                    'clients_visible' => User::query()
                        ->inCrmScope($actor, CrmScope::MINE)
                        ->count(),
                    'clients_in_department' => User::query()->visibleInCrm($actor)->count(),
                    // Может расфокусироваться на отдел, передав scope=department.
                    'sees_department' => $actor->can('crm-department.view'),
                    // Разрезы по менеджерам и план отдела — РОПовское.
                    'sees_all' => $actor->can('crm-clients-all.view'),
                ],
                'sections' => $this->registry->sections(),
                'operations' => $operations,
                'docs' => [
                    'ui' => url('/docs/crm-api'),
                    'openapi' => url('/docs/crm-api.json'),
                    'mcp' => url('/mcp/crm'),
                ],
            ],
        ]);
    }

    /**
     * Выполнить операцию, к маршруту которой пришёл запрос.
     */
    public function run(Request $request): JsonResponse
    {
        $operation = $this->operation($request);
        $actor = $this->actor($request);

        // Параметры пути кладём последними: адрес — источник более надёжный,
        // чем тело, и `clients/5/profile` с `client=9` в теле должен остаться
        // операцией по клиенту 5.
        $args = array_merge($request->all(), $request->route()?->parameters() ?? []);

        try {
            $result = $this->runner->run($operation, $actor, $args);
        } catch (OperationDenied $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ModelNotFoundException $e) {
            // Ловим раньше RuntimeException, от которого он наследуется: запись
            // вне скоупа — это 404, а не «бизнес-правило отказало». Перебрасываем,
            // чтобы ответ собрал штатный обработчик.
            throw $e;
        } catch (InvalidArgumentException|RuntimeException $e) {
            // Отказ бизнес-правила (выключенная отправка писем, неподходящий
            // исполнитель) — не 500: агенту нужно понять, что делать дальше.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
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

        abort_if($actor === null, 401, 'Требуется токен агента.');

        return $actor;
    }

    /**
     * Права актора среди тех, что вообще используются операциями.
     *
     * @return list<string>
     */
    private function permissions(User $actor): array
    {
        $permissions = array_unique(array_map(
            fn (Operation $operation): string => $operation->permission,
            $this->registry->all(),
        ));

        sort($permissions);

        return array_values(array_filter($permissions, fn (string $p): bool => $actor->can($p)));
    }
}
