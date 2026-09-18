<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Client\ClientAskManager;
use App\Mcp\Tools\Client\ClientBalance;
use App\Mcp\Tools\Client\ClientCall;
use App\Mcp\Tools\Client\ClientCatalog;
use App\Mcp\Tools\Client\ClientCreateOrder;
use App\Mcp\Tools\Client\ClientDescribe;
use App\Mcp\Tools\Client\ClientDocuments;
use App\Mcp\Tools\Client\ClientFaq;
use App\Mcp\Tools\Client\ClientOrderStatus;
use App\Mcp\Tools\Client\ClientPrices;
use App\Mcp\Tools\Client\ClientPromotions;
use App\Models\User;
use App\Services\Client\Api\Usage\UsageContext;
use App\Services\Client\Api\Usage\UsageRecorder;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;
use Laravel\Mcp\Server\Transport\JsonRpcResponse;
use Throwable;

/**
 * MCP-сервер клиента: кабинет покупателя глазами его ИИ-агента.
 *
 * Тонкая витрина над реестром операций клиентского API v1: те же операции,
 * гейты, юрлицо, идемпотентность и аудит, что у REST `/api/client/v1/*`.
 * Отдельная граница: токен клиента (`api_tokens`) не открывает ничего из CRM
 * и аналитики, а токены сотрудников не открывают этот сервер.
 */
class ClientServer extends Server
{
    protected string $name = 'Pecado — кабинет клиента';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
    Работа в личном кабинете оптового покупателя Pecado от имени клиента: цены и остатки,
    корзины и оформление заказов, статусы заказов и реализаций, резервы, возвраты,
    документы, оплаты и платёжки, реквизиты, вопросы менеджеру и настройки уведомлений,
    а также контент сайта: акции, новости, подборки, информационные страницы и FAQ.

    ## Порядок работы

    1. `client-catalog` — юрлица клиента, состояние разделов и все операции с флагом
       `allowed`. Не вызывайте то, что помечено недоступным: раздел выключен для
       клиента, и повтор ничего не изменит.
    2. `client-describe` — схема аргументов нужной операции.
    3. `client-call` — выполнение.

    Для частых сценариев есть ярлыки, они дешевле трёх вызовов: `client-prices`,
    `client-order-status`, `client-create-order`, `client-balance`, `client-documents`,
    `client-promotions`, `client-faq`, `client-ask-manager`.

    ## Акции, новости и FAQ — отвечайте по сайту

    Об акциях, скидках, подарках, доставке, оплате и условиях работы **не отвечайте по
    памяти**: сначала `client-promotions` (действующие для этого клиента акции с периодом
    и условиями) или `client-faq` (вопросы-ответы и список страниц). Новости —
    `news.list`/`news.get`, подборки — `collections.list`/`collections.products`, текст
    страницы — `pages.get`. Всё отобрано по региону клиента, как на сайте. Если на сайте
    ответа нет — `client-ask-manager`, а не догадка. Промо-позицию с `how_applied: manager`
    добавит менеджер после оформления — не обещайте её в заказе сразу.

    ## Записи необратимы и идут от имени клиента

    Заказ уходит в 1С и в сборку на складе, вопрос — менеджеру, возврат — на согласование.
    «Пробных» записей здесь нет. Отменить заказ можно только пока он не передан в
    сборку и только при включённом режиме отмены; резервный заказ отменяется всегда.

    ## Юрлицо

    Заказ, документы и долг живут на контрагенте (юрлице), а не на аккаунте. Основная
    компания подставляется сама; если компаний несколько и ни одна не основная, придёт
    отказ `company_required` с перечнем — **спросите у человека, не выбирайте сами**.

    ## Идемпотентность

    На создание заказа (`orders.create`, `checkout.submit`, ярлык `client-create-order`)
    `idempotency_key` обязателен. Сгенерируйте уникальный ключ на каждый новый заказ;
    при обрыве или таймауте **повторяйте с тем же ключом**, а не с новым — повтор вернёт
    прежний результат, а не создаст дубль. Тот же ключ с другим составом отклоняется.

    ## Два способа заказать

    `orders.create` — по списку идентификаторов, «дружественный приём»: недоступные
    позиции не блокируют заказ, а перечисляются в `meta.not_accepted` и `meta.partial`,
    количества урезаются по остатку. `checkout.submit` — оформить собранную корзину
    целиком, как кнопка «Оформить» в кабинете: при изменившихся остатках откажет
    (`stock_changed`) — выполните `checkout.normalize` и повторите.

    ## Что нужно знать заранее

    **Остатки и цены — по региону клиента.** «Нет в наличии» ≠ «нет товара»: у позиции
    может быть предзаказ со сроком поставки (`preorder_lead_days`, обычно 7–9 дней),
    он уезжает отдельным заказом того же оформления.

    **Разделы могут быть выключены** (документы, оплаты, договоры, резервы) — смотрите
    `features` и `allowed` в каталоге, а не спорьте с 403. Причина в `denied_reason`.

    **Уведомления клиенту выключены по умолчанию.** Чтобы клиент получал письма о
    смене статуса заказа или новых документах, включите нужный тип через
    `notifications.update` (адресат `login` — почта аккаунта).

    **Токен** выдаётся и отзывается на странице `/api-tokens` в кабинете; один токен
    даёт все операции. Тот же токен работает и в REST `/api/client/v1/*` (Bearer).

    ## Чего API не решает — спросите менеджера

    Объединить несколько заказов в одну отправку, придержать заказ до прихода
    предзаказа, самовывоз к определённому часу, трек-номер доставки, разблокировка
    по долгу, замена недовезённого товара — это `client-ask-manager`. Не выдумывайте
    ответ за менеджера: ответ появится в `questions.get`.

    ## Что здесь недоступно

    Аналитика, CRM и данные других клиентов через этот сервер недоступны ни при каком
    токене — это другие серверы с другими токенами.
    MARKDOWN;

    /**
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        ClientCatalog::class,
        ClientDescribe::class,
        ClientCall::class,
        ClientPrices::class,
        ClientOrderStatus::class,
        ClientCreateOrder::class,
        ClientBalance::class,
        ClientDocuments::class,
        ClientPromotions::class,
        ClientFaq::class,
        ClientAskManager::class,
    ];

    /** Идентификатор сессии, выданный в текущем `initialize` (см. generateSessionId). */
    private ?string $issuedSessionId = null;

    /**
     * Подключение агента: ответ штатный, а clientInfo («claude-code 2.1.0»)
     * уходит в журнал вызовов и запоминается по сессии — каждая следующая
     * строка инструмента будет знать, чей это агент.
     */
    protected function handleInitializeMessage(JsonRpcRequest $request, ServerContext $context): void
    {
        $this->issuedSessionId = null;

        parent::handleInitializeMessage($request, $context);

        $actor = Auth::user();

        if ($actor instanceof User) {
            $clientInfo = $request->params['clientInfo'] ?? null;

            app(UsageRecorder::class)->mcpConnected(
                $actor,
                $this->issuedSessionId,
                is_array($clientInfo) ? $clientInfo : null,
            );
        }
    }

    protected function generateSessionId(): string
    {
        return $this->issuedSessionId = parent::generateSessionId();
    }

    /**
     * Вызов инструмента — строка журнала: инструмент, операция реестра (её
     * отмечает OperationRunner через UsageContext), исход и длительность.
     * Журнал не вмешивается в ответ: исключение уходит дальше как было.
     */
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        if ($request->method !== 'tools/call') {
            return parent::runMethodHandle($request, $context);
        }

        $usage = app(UsageContext::class);
        $usage->begin();
        $started = hrtime(true);

        try {
            $response = parent::runMethodHandle($request, $context);
        } catch (Throwable $e) {
            $this->recordToolCall($request, false, $usage->errorCode() ?? 'exception', $started);

            throw $e;
        }

        // Потоковый ответ (генератор) исхода не сообщает — считаем успехом:
        // ошибки инструментов кабинета приходят обычным ответом с isError.
        $ok = true;

        if ($response instanceof JsonRpcResponse) {
            $payload = $response->toArray();
            $result = $payload['result'] ?? null;
            $ok = ! isset($payload['error']) && ! (is_array($result) && ($result['isError'] ?? false) === true);
        }

        $this->recordToolCall($request, $ok, $usage->errorCode(), $started);

        return $response;
    }

    private function recordToolCall(JsonRpcRequest $request, bool $ok, ?string $errorCode, int $startedAt): void
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return;
        }

        $tool = (string) ($request->params['name'] ?? '');

        app(UsageRecorder::class)->mcpToolCalled(
            $actor,
            $request->sessionId,
            mb_substr($tool !== '' ? $tool : 'unknown', 0, 64),
            $ok,
            $errorCode,
            (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }
}
