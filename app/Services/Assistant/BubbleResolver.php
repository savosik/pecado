<?php

namespace App\Services\Assistant;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Debt\CabinetDebtStatus;
use App\Services\Pickup\OrderFulfilmentResolver;
use App\Services\Stock\StockService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Реплика иконки-консультанта: одна короткая фраза, которая лучше всего
 * подходит странице и данным клиента.
 *
 * Фразы выбирает код, а не модель: стоят ноль токенов и появляются мгновенно.
 * Каждая реплика — уже ответ или действие из данных, никогда «спросить у
 * менеджера» (решение заказчика 19.09.2026). Ключ фразы уходит в воронку,
 * чтобы видеть, какие реплики на каких страницах ведут в диалог.
 */
final class BubbleResolver
{
    public function __construct(
        private readonly StockService $stock,
        private readonly OrderFulfilmentResolver $fulfilment,
        private readonly CabinetDebtStatus $debt,
    ) {}

    /**
     * @param  array{type: string, id: string|null, title: string|null, url: string|null}  $page
     * @param  list<string>  $recentlyShown  ключи, которые клиент уже видел — не повторяем
     * @return array{key: string, text: string, question: string}|null
     */
    public function resolve(User $user, array $page, bool $intro, array $recentlyShown = []): ?array
    {
        if ($intro) {
            return self::bubble('intro', 'Я помощник: цены, заказы, документы, долг. Спросите', 'Что ты умеешь?');
        }

        $candidates = [];

        try {
            $candidates = array_merge(
                $this->overdue($user),
                $this->reserve($user),
                $this->forPage($user, $page),
            );
        } catch (Throwable $e) {
            report($e);
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== null && ! in_array($candidate['key'], $recentlyShown, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array{type: string, id: string|null, title: string|null, url: string|null}  $page
     * @return list<array{key: string, text: string, question: string}|null>
     */
    private function forPage(User $user, array $page): array
    {
        return match ($page['type']) {
            'product' => [$this->product($user, $page)],
            'order' => [$this->order($user, $page)],
            'catalog', 'search' => [
                self::bubble('catalog.file', 'Пришлите прайс или фото — подберу по вашим ценам', 'Хочу подобрать товары по своему прайс-листу'),
            ],
            'defects' => [self::bubble('defects.browse', 'Покажу уценку с фото и остатками — что ищете?', 'Какие товары с уценкой сейчас в продаже? Покажи с фото и ценами')],
            'cart' => [self::bubble('cart.checkout', 'Проверить остатки по корзине и оформить?', 'Проверь остатки по моей корзине и оформи заказ')],
            'orders' => [self::bubble('orders.status', 'Показать, что с последними заказами?', 'Что с моими последними заказами?')],
            'reserves' => [self::bubble('reserves.list', 'Показать сроки по резервам?', 'Какие резервы у меня истекают и когда?')],
            'documents' => [self::bubble('documents.reconciliation', 'Сделать сверку за '.self::lastMonth().'?', 'Сделай акт сверки за '.self::lastMonth())],
            'finance' => [self::bubble('finance.schedule', 'Показать график оплат?', 'Покажи мой график оплат и долг')],
            'shipments' => [self::bubble('shipments.documents', 'Скачать документы по последней отгрузке?', 'Дай документы по последней отгрузке')],
            'returns' => [self::bubble('returns.create', 'Оформить возврат по реализации?', 'Хочу оформить возврат')],
            'promotions' => [self::bubble('promotions.mine', 'Какие акции действуют для вас — показать?', 'Какие акции сейчас действуют для меня?')],
            'checkout' => [self::bubble('checkout.help', 'Проверить состав перед оформлением?', 'Проверь мой заказ перед оформлением')],
            default => [self::bubble('cabinet.hello', 'Цены, заказы, документы, долг — спросите', 'Что ты умеешь?')],
        };
    }

    /**
     * @param  array{type: string, id: string|null, title: string|null, url: string|null}  $page
     * @return array{key: string, text: string, question: string}|null
     */
    private function product(User $user, array $page): ?array
    {
        $product = $page['id'] !== null ? Product::query()->find((int) $page['id']) : null;

        if ($product === null) {
            return self::bubble('product.ask', 'Посчитать под ваш заказ?', 'Посчитай этот товар под мой заказ');
        }

        $stock = $this->stock->getStock($product, $user);
        $name = (string) ($product->name ?: $page['title'] ?: 'товар');

        if ($stock['available'] > 0) {
            return self::bubble(
                'product.in_stock',
                'Есть '.self::pieces($stock['available']).' — добавить в корзину?',
                'Добавь «'.$name.'» в корзину и скажи цену для меня',
            );
        }

        if ($stock['preorder'] > 0 || $user->preordersEnabled()) {
            return self::bubble(
                'product.preorder',
                'Сейчас только предзаказ — узнать срок и оформить?',
                'Когда приедет «'.$name.'» по предзаказу и сколько стоит для меня?',
            );
        }

        return self::bubble('product.alternatives', 'Нет в наличии — подобрать замену?', 'Подбери замену для «'.$name.'» из наличия');
    }

    /**
     * @param  array{type: string, id: string|null, title: string|null, url: string|null}  $page
     * @return array{key: string, text: string, question: string}|null
     */
    private function order(User $user, array $page): ?array
    {
        $order = $page['id'] !== null
            ? Order::query()->where('user_id', $user->getKey())->find((int) $page['id'])
            : null;

        if ($order === null) {
            return self::bubble('orders.status', 'Показать, что с последними заказами?', 'Что с моими последними заказами?');
        }

        $view = $this->fulfilment->forOrder($order);
        $number = (string) ($order->number ?? $page['title'] ?? $order->id);
        $question = 'Что с заказом '.$number.'?';

        return match ($view['stage'] ?? '') {
            'picking' => self::bubble(
                'order.picking',
                'Сборка идёт'.(isset($view['promise']['text']) ? ', '.mb_strtolower($view['promise']['text']) : '').' — показать состав?',
                $question,
            ),
            'ready' => self::bubble('order.ready', 'Собран, ждёт выдачи — показать состав и пропуск?', 'Заказ '.$number.' собран — покажи состав и оформи пропуск курьеру'),
            'sent_to_warehouse' => self::bubble('order.sent', 'Передан складу'.(isset($view['promise']['text']) ? ', '.mb_strtolower($view['promise']['text']) : '').' — уточнить состав?', $question),
            'handed_over' => self::bubble('order.handed', 'Выдан — нужны документы по нему?', 'Дай документы по заказу '.$number),
            'shipped' => self::bubble('order.shipped', 'Отгружен — показать реализацию и документы?', 'Покажи реализацию и документы по заказу '.$number),
            'reserved' => self::bubble('order.reserved', 'В резерве — отправить в отгрузку?', 'Отправь заказ '.$number.' в отгрузку'),
            default => self::bubble('order.status', 'Показать статус и состав?', $question),
        };
    }

    /**
     * @return list<array{key: string, text: string, question: string}|null>
     */
    private function overdue(User $user): array
    {
        $debt = $this->debt->forUser($user);

        if ($debt === null || ! ($debt['visible'] ?? false) || (float) ($debt['overdue_amount'] ?? 0) <= 0) {
            return [];
        }

        $days = (int) ($debt['age_days'] ?? 0);
        $amount = self::money((float) $debt['overdue_amount']);

        return [self::bubble(
            'finance.overdue',
            'Просрочка '.($days > 0 ? self::days($days).' на ' : '').$amount.' — показать, что и когда?',
            'Покажи, какие счета просрочены, на сколько и когда платить',
        )];
    }

    /**
     * @return list<array{key: string, text: string, question: string}|null>
     */
    private function reserve(User $user): array
    {
        $expiring = Order::query()
            ->where('user_id', $user->getKey())
            ->whereNotNull('reserved_until')
            ->whereBetween('reserved_until', [now(), now()->addDays(2)])
            ->orderBy('reserved_until')
            ->first(['id', 'number', 'reserved_until']);

        if ($expiring === null) {
            return [];
        }

        $until = $expiring->reserved_until instanceof Carbon ? $expiring->reserved_until : Carbon::parse((string) $expiring->reserved_until);
        $when = $until->isToday() ? 'сегодня' : ($until->isTomorrow() ? 'завтра' : $until->format('d.m'));

        return [self::bubble(
            'reserve.expiring',
            'Резерв '.$expiring->number.' истекает '.$when.' — продлить или отгрузить?',
            'Резерв '.$expiring->number.' истекает — какие варианты?',
        )];
    }

    /**
     * @return array{key: string, text: string, question: string}
     */
    private static function bubble(string $key, string $text, string $question): array
    {
        return ['key' => $key, 'text' => mb_substr($text, 0, 90), 'question' => $question];
    }

    private static function pieces(int $n): string
    {
        $n = min($n, 999);
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        $word = $mod100 >= 11 && $mod100 <= 14 ? 'штук' : ($mod10 === 1 ? 'штука' : ($mod10 >= 2 && $mod10 <= 4 ? 'штуки' : 'штук'));

        return ($n === 999 ? 'больше 999' : $n).' '.$word;
    }

    private static function days(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;
        $word = $mod100 >= 11 && $mod100 <= 14 ? 'дней' : ($mod10 === 1 ? 'день' : ($mod10 >= 2 && $mod10 <= 4 ? 'дня' : 'дней'));

        return $n.' '.$word;
    }

    private static function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' ₽';
    }

    private static function lastMonth(): string
    {
        $months = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];

        return $months[now()->subMonthNoOverflow()->month - 1];
    }
}
