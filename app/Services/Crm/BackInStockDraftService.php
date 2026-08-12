<?php

namespace App\Services\Crm;

use App\Models\CrmBackInStockOffer;
use App\Models\CrmEmail;
use App\Models\CrmEmailTemplate;
use App\Models\Product;
use App\Models\ProductAvailabilityEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Черновики писем «товар, который вы берёте, снова в продаже».
 *
 * Повод честный: не «купите что-нибудь», а «вернулось то, что вы правда
 * покупаете». Такое письмо менеджер отправит с удовольствием, а клиент
 * прочитает.
 *
 * Четыре решения, определяющих сервис:
 *
 * 1. **Только черновики.** Ничего не уходит само, даже при включённом
 *    MAIL_FEATURE_CRM_OUTBOUND. Автоматически ушедшее письмо с неверным
 *    поводом (товар вернулся и через час кончился, клиент давно ушёл)
 *    стоит дороже сэкономленного клика. Тот же принцип, что с ИИ в crm-19:
 *    система предлагает, а не делает молча.
 * 2. **Одно письмо на клиента, а не на товар.** Вернулось пять позиций —
 *    это письмо со списком. Иначе в день большой приёмки менеджер получил бы
 *    сотню черновиков и не отправил бы ни одного.
 * 3. **Дедупликация обязательна.** Остаток дребезжит около порога, и без
 *    журнала предложений клиент получил бы серию одинаковых писем.
 * 4. **Это не рассылка.** Результат — N черновиков в почте конкретных
 *    менеджеров, каждый со своим адресатом. Инфраструктуры кампаний
 *    в проекте нет, и эта карточка её не вводит.
 */
class BackInStockDraftService
{
    /**
     * Собрать черновики по товарам, вернувшимся в продажу.
     *
     * @return array{drafts: int, clients: int, products: int, skipped: int, truncated: bool}
     */
    public function run(?Carbon $since = null): array
    {
        $since ??= Carbon::now()->subDays($this->sinceDays());

        $products = $this->productsBackInStock($since);

        if ($products === []) {
            return $this->summary(0, 0, 0, 0, false);
        }

        $byClient = $this->buyersOf($products);
        $limit = $this->maxDrafts();
        $truncated = count($byClient) > $limit;

        if ($truncated) {
            // Молчаливое усечение читается как «покрыли всех», когда покрыли
            // половину. Пишем в лог, сколько осталось за бортом.
            Log::info('back-in-stock: список черновиков обрезан лимитом', [
                'clients_found' => count($byClient),
                'limit' => $limit,
            ]);

            $byClient = array_slice($byClient, 0, $limit, preserve_keys: true);
        }

        $drafts = 0;
        $skippedNoEmail = 0;

        foreach ($byClient as $clientId => $productIds) {
            $result = $this->draftFor((int) $clientId, $productIds);

            if ($result === 'created') {
                $drafts++;
            } else {
                // Нет адреса или у карточки менеджера нет учётки сотрудника —
                // писать некому либо не от кого. Считаем, чтобы прогон
                // не выглядел «ничего не нашёл».
                $skippedNoEmail++;
            }
        }

        return $this->summary($drafts, count($byClient), count($products), $skippedNoEmail, $truncated);
    }

    /**
     * Товары, вернувшиеся в продажу за период.
     *
     * @return list<int>
     */
    private function productsBackInStock(Carbon $since): array
    {
        /** @var list<int> $ids */
        $ids = ProductAvailabilityEvent::query()
            ->backInStockSince($since)
            ->where('quantity', '>=', $this->minQuantity())
            ->pluck('product_id')
            ->unique()
            ->values()
            ->map('intval')
            ->all();

        return $ids;
    }

    /**
     * Активные покупатели этих товаров: кто и что берёт.
     *
     * «Покупал один раз год назад» — не активный покупатель, и письмо ему
     * испортит впечатление от повода. Отсюда два порога: глубина истории
     * и минимальное число поставок.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<int>> клиент => товары
     */
    private function buyersOf(array $productIds): array
    {
        $historySince = Carbon::now()->subDays($this->historyDays());
        $dedupSince = Carbon::now()->subDays($this->dedupDays());

        $rows = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->whereIn('shipment_items.product_id', $productIds)
            ->whereNull('shipments.deleted_at')
            // Бизнес-дата: историю отгрузок импортировали из 1С в мае 2026,
            // и created_at у половины базы — дата импорта, а не поставки.
            ->whereRaw('COALESCE(shipments.erp_created_at, shipments.date, shipments.created_at) >= ?', [$historySince])
            ->whereNotNull('shipments.user_id')
            ->groupBy('shipments.user_id', 'shipment_items.product_id')
            ->havingRaw('COUNT(*) >= ?', [$this->minPurchases()])
            ->get([
                'shipments.user_id',
                'shipment_items.product_id',
                DB::raw('COUNT(*) as purchases'),
            ]);

        $offered = $this->alreadyOffered($dedupSince);
        $byClient = [];

        foreach ($rows as $row) {
            $clientId = (int) $row->user_id;
            $productId = (int) $row->product_id;

            if (isset($offered[$clientId][$productId])) {
                continue;
            }

            $byClient[$clientId][] = $productId;
        }

        return $byClient;
    }

    /**
     * Что кому уже предлагали в пределах окна.
     *
     * @return array<int, array<int, true>>
     */
    private function alreadyOffered(Carbon $since): array
    {
        $map = [];

        CrmBackInStockOffer::query()
            ->where('offered_at', '>=', $since)
            ->select(['client_user_id', 'product_id'])
            ->each(function ($offer) use (&$map): void {
                $map[(int) $offer->client_user_id][(int) $offer->product_id] = true;
            });

        return $map;
    }

    /**
     * Черновик одному клиенту со списком его товаров.
     *
     * @param  list<int>  $productIds
     * @return 'created'|'no_email'|'no_manager'
     */
    private function draftFor(int $clientId, array $productIds): string
    {
        $client = User::query()->with('personalManager.user')->find($clientId);

        if ($client === null || blank($client->email)) {
            return 'no_email';
        }

        // Письмо ложится в почту персонального менеджера клиента — тот же
        // принцип адресации, что у уведомлений о заказах.
        $manager = $client->personalManager?->user;

        if ($manager === null) {
            return 'no_manager';
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->get(['id', 'name', 'sku']);

        if ($products->isEmpty()) {
            return 'no_email';
        }

        return DB::transaction(function () use ($client, $manager, $products, $productIds): string {
            $email = CrmEmail::create([
                'user_id' => $manager->getKey(),
                'client_user_id' => $client->getKey(),
                'to' => [$client->email],
                'subject' => $this->subject($products->count()),
                'body_html' => $this->body($client, $manager, $products),
                // Черновик и только черновик: отправляет менеджер руками.
                'status' => 'draft',
            ]);

            foreach ($productIds as $productId) {
                CrmBackInStockOffer::create([
                    'client_user_id' => $client->getKey(),
                    'product_id' => $productId,
                    'email_id' => $email->getKey(),
                    'offered_at' => Carbon::now(),
                ]);
            }

            return 'created';
        });
    }

    private function subject(int $count): string
    {
        return $count === 1
            ? 'Товар, который вы покупаете, снова в наличии'
            : 'Товары, которые вы покупаете, снова в наличии';
    }

    /**
     * Тело письма.
     *
     * Если в справочнике есть шаблон с нужным ключом — берём его и подставляем
     * список позиций. Так текст правит отдел продаж, а не разработчик.
     *
     * @param  \Illuminate\Support\Collection<int, Product>  $products
     */
    private function body(User $client, User $manager, $products): string
    {
        $items = $products
            ->map(fn (Product $product): string => '<li>'.e($product->name).($product->sku ? ' (арт. '.e($product->sku).')' : '').'</li>')
            ->implode('');

        $list = '<ul>'.$items.'</ul>';

        $template = CrmEmailTemplate::query()
            ->where('name', $this->templateName())
            ->where('is_active', true)
            ->first();

        if ($template !== null) {
            return strtr($template->body_html, [
                '{{client_name}}' => e($client->display_name),
                '{{manager_name}}' => e($manager->name),
                '{{products}}' => $list,
            ]);
        }

        return '<p>Здравствуйте, '.e($client->display_name).'!</p>'
            .'<p>Товар, который вы у нас регулярно берёте, снова в наличии:</p>'
            .$list
            .'<p>Если нужно отложить — напишите в ответ на это письмо.</p>'
            .'<p>С уважением,<br>'.e($manager->name).'</p>';
    }

    /**
     * @return array{drafts: int, clients: int, products: int, skipped: int, truncated: bool}
     */
    private function summary(int $drafts, int $clients, int $products, int $skippedNoEmail, bool $truncated): array
    {
        return [
            'drafts' => $drafts,
            'clients' => $clients,
            'products' => $products,
            'skipped' => $skippedNoEmail,
            'truncated' => $truncated,
        ];
    }

    private function sinceDays(): int
    {
        return max(1, (int) config('crm.back_in_stock.since_days', 3));
    }

    private function minQuantity(): int
    {
        return max(1, (int) config('crm.back_in_stock.min_quantity', 10));
    }

    private function historyDays(): int
    {
        return max(30, (int) config('crm.back_in_stock.history_days', 365));
    }

    private function minPurchases(): int
    {
        return max(1, (int) config('crm.back_in_stock.min_purchases', 2));
    }

    private function dedupDays(): int
    {
        return max(1, (int) config('crm.back_in_stock.dedup_days', 60));
    }

    private function maxDrafts(): int
    {
        return max(1, (int) config('crm.back_in_stock.max_drafts', 50));
    }

    private function templateName(): string
    {
        return (string) config('crm.back_in_stock.template_name', 'Товар снова в наличии');
    }
}
