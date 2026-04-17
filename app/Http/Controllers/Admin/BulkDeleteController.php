<?php

namespace App\Http\Controllers\Admin;

use App\Jobs\BulkDeleteJob;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * Массовое удаление всех записей раздела.
 *
 * DELETE /admin/bulk-delete-all/{resource}  — запускает фоновую Job
 * GET    /admin/bulk-delete-status/{resource} — проверка прогресса
 */
class BulkDeleteController extends Controller
{
    /**
     * Реестр ресурсов: slug => [model, method, permission, label].
     *
     * method:
     *   'each'     — Model::all()->each->delete()  (каскад через события модели / Spatie Media)
     *   'truncate' — TRUNCATE TABLE (без событий, мгновенно)
     */
    private function registry(): array
    {
        return [
            'products' => [
                'model' => \App\Models\Product::class,
                'method' => 'each',
                'permission' => 'products.delete',
                'label' => 'Товары',
            ],
            'categories' => [
                'model' => \App\Models\Category::class,
                'method' => 'each',
                'permission' => 'categories.delete',
                'label' => 'Категории',
            ],
            'brands' => [
                'model' => \App\Models\Brand::class,
                'method' => 'each',
                'permission' => 'brands.delete',
                'label' => 'Бренды',
            ],
            'product-models' => [
                'model' => \App\Models\ProductModel::class,
                'method' => 'truncate',
                'permission' => 'product-models.delete',
                'label' => 'Модели товаров',
            ],
            'attributes' => [
                'model' => \App\Models\Attribute::class,
                'method' => 'each',
                'permission' => 'attributes.delete',
                'label' => 'Атрибуты',
            ],
            'attribute-groups' => [
                'model' => \App\Models\AttributeGroup::class,
                'method' => 'truncate',
                'permission' => 'attribute-groups.delete',
                'label' => 'Группы атрибутов',
            ],
            'size-charts' => [
                'model' => \App\Models\SizeChart::class,
                'method' => 'truncate',
                'permission' => 'size-charts.delete',
                'label' => 'Размерные сетки',
            ],
            'product-barcodes' => [
                'model' => \App\Models\ProductBarcode::class,
                'method' => 'truncate',
                'permission' => 'product-barcodes.delete',
                'label' => 'Штрихкоды',
            ],
            'certificates' => [
                'model' => \App\Models\Certificate::class,
                'method' => 'each',
                'permission' => 'certificates.delete',
                'label' => 'Сертификаты',
            ],
            'product-exports' => [
                'model' => \App\Models\ProductExport::class,
                'method' => 'truncate',
                'permission' => 'product-exports.delete',
                'label' => 'Выгрузки товаров',
            ],
            'tags' => [
                'model' => \Spatie\Tags\Tag::class,
                'method' => 'each',
                'permission' => 'tags.delete',
                'label' => 'Теги',
            ],
            'pages' => [
                'model' => \App\Models\Page::class,
                'method' => 'each',
                'permission' => 'pages.delete',
                'label' => 'Страницы',
            ],
            'banners' => [
                'model' => \App\Models\Banner::class,
                'method' => 'each',
                'permission' => 'banners.delete',
                'label' => 'Баннеры',
            ],
            'media' => [
                'model' => \App\Models\Media::class,
                'method' => 'each',
                'permission' => 'media.delete',
                'label' => 'Медиафайлы',
            ],
            'stories' => [
                'model' => \App\Models\Story::class,
                'method' => 'each',
                'permission' => 'stories.delete',
                'label' => 'Сторис',
            ],
            'articles' => [
                'model' => \App\Models\Article::class,
                'method' => 'each',
                'permission' => 'articles.delete',
                'label' => 'Статьи',
            ],
            'brand-stories' => [
                'model' => \App\Models\BrandStory::class,
                'method' => 'each',
                'permission' => 'brand-stories.delete',
                'label' => 'О брендах',
            ],
            'news' => [
                'model' => \App\Models\News::class,
                'method' => 'each',
                'permission' => 'news.delete',
                'label' => 'Новости',
            ],
            'menu-items' => [
                'model' => \App\Models\MenuItem::class,
                'method' => 'truncate',
                'permission' => 'menu-items.delete',
                'label' => 'Пункты меню',
            ],
            'faqs' => [
                'model' => \App\Models\Faq::class,
                'method' => 'truncate',
                'permission' => 'faqs.delete',
                'label' => 'FAQ',
            ],
            'warehouses' => [
                'model' => \App\Models\Warehouse::class,
                'method' => 'truncate',
                'permission' => 'warehouses.delete',
                'label' => 'Склады',
            ],
            'regions' => [
                'model' => \App\Models\Region::class,
                'method' => 'each',
                'permission' => 'regions.delete',
                'label' => 'Регионы',
            ],
            'orders' => [
                'model' => \App\Models\Order::class,
                'method' => 'each',
                'permission' => 'orders.delete',
                'label' => 'Заказы',
            ],
            'carts' => [
                'model' => \App\Models\Cart::class,
                'method' => 'each',
                'permission' => 'carts.delete',
                'label' => 'Корзины',
            ],
            'returns' => [
                'model' => \App\Models\ProductReturn::class,
                'method' => 'each',
                'permission' => 'returns.delete',
                'label' => 'Возвраты',
            ],
            'shipments' => [
                'model' => \App\Models\Shipment::class,
                'method' => 'each',
                'permission' => 'shipments.delete',
                'label' => 'Реализации',
            ],
            'favorites' => [
                'model' => \App\Models\Favorite::class,
                'method' => 'truncate',
                'permission' => 'favorites.delete',
                'label' => 'Избранное',
            ],
            'wishlist' => [
                'model' => \App\Models\WishlistItem::class,
                'method' => 'truncate',
                'permission' => 'wishlist.delete',
                'label' => 'Список желаний',
            ],
            'promotions' => [
                'model' => \App\Models\Promotion::class,
                'method' => 'each',
                'permission' => 'promotions.delete',
                'label' => 'Акции',
            ],
            'product-selections' => [
                'model' => \App\Models\ProductSelection::class,
                'method' => 'each',
                'permission' => 'product-selections.delete',
                'label' => 'Подборки товаров',
            ],
            'users' => [
                'model' => \App\Models\User::class,
                'method' => 'each',
                'permission' => 'users.delete',
                'label' => 'Пользователи',
            ],
            'companies' => [
                'model' => \App\Models\Company::class,
                'method' => 'each',
                'permission' => 'companies.delete',
                'label' => 'Компании',
            ],
            'company-bank-accounts' => [
                'model' => \App\Models\CompanyBankAccount::class,
                'method' => 'truncate',
                'permission' => 'company-bank-accounts.delete',
                'label' => 'Банковские счета',
            ],
            'delivery-addresses' => [
                'model' => \App\Models\DeliveryAddress::class,
                'method' => 'truncate',
                'permission' => 'delivery-addresses.delete',
                'label' => 'Адреса доставки',
            ],
            'user-questionnaires' => [
                'model' => \App\Models\UserQuestionnaire::class,
                'method' => 'truncate',
                'permission' => 'user-questionnaires.delete',
                'label' => 'Анкеты',
            ],
            'client-statuses' => [
                'model' => \App\Models\ClientStatus::class,
                'method' => 'each',
                'permission' => 'client-statuses.delete',
                'label' => 'Статусы клиентов',
            ],
            'currencies' => [
                'model' => \App\Models\Currency::class,
                'method' => 'truncate',
                'permission' => 'currencies.delete',
                'label' => 'Валюты',
            ],
            'contractor-balances' => [
                'model' => \App\Models\ContractorBalance::class,
                'method' => 'each',
                'permission' => 'contractor-balances.delete',
                'label' => 'Балансы контрагентов',
            ],
            'individual-prices' => [
                'model' => \App\Models\IndividualPrice::class,
                'method' => 'truncate',
                'permission' => 'individual-prices.delete',
                'label' => 'Индивидуальные цены',
            ],
            'erp-processed-messages' => [
                'model' => \App\Models\ErpProcessedMessage::class,
                'method' => 'truncate',
                'permission' => 'erp-bus.view',
                'label' => 'Обработанные сообщения ERP',
            ],
            'roles' => [
                'model' => \Spatie\Permission\Models\Role::class,
                'method' => 'each',
                'permission' => 'roles.delete',
                'label' => 'Роли',
            ],
        ];
    }

    /**
     * Реестр soft-deleted ресурсов для окончательного удаления.
     */
    private function forceDeleteRegistry(): array
    {
        return [
            'orders' => [
                'model' => \App\Models\Order::class,
                'permission' => 'orders.delete',
                'label' => 'Заказы (удалённые)',
            ],
            'shipments' => [
                'model' => \App\Models\Shipment::class,
                'permission' => 'shipments.delete',
                'label' => 'Реализации (удалённые)',
            ],
            'returns' => [
                'model' => \App\Models\ProductReturn::class,
                'permission' => 'returns.delete',
                'label' => 'Возвраты (удалённые)',
            ],
            'companies' => [
                'model' => \App\Models\Company::class,
                'permission' => 'companies.delete',
                'label' => 'Компании (удалённые)',
            ],
        ];
    }

    /**
     * Окончательно удалить все soft-deleted записи ресурса через фоновую Job.
     *
     * DELETE /admin/bulk-force-delete-all/{resource}
     */
    public function forceDestroyAll(Request $request, string $resource)
    {
        $registry = $this->forceDeleteRegistry();

        if (! isset($registry[$resource])) {
            abort(404, "Ресурс «{$resource}» не поддерживает окончательное удаление.");
        }

        $config = $registry[$resource];

        if (! $request->user()->can($config['permission'])) {
            abort(403, 'Недостаточно прав для удаления.');
        }

        $cacheKey = BulkDeleteJob::cacheKey("force:{$resource}");
        $progress = Cache::get($cacheKey);

        if ($progress && $progress['status'] === 'running') {
            return back()->with('warning', "Удаление «{$config['label']}» уже выполняется. Подождите завершения.");
        }

        BulkDeleteJob::dispatch(
            "force:{$resource}",
            $config['model'],
            'forceDelete',
            $config['label']
        );

        return back()->with('success', "Окончательное удаление «{$config['label']}» запущено в фоне.");
    }

    /**
     * Проверить статус фонового окончательного удаления.
     *
     * GET /admin/bulk-force-delete-status/{resource}
     */
    public function forceStatus(string $resource)
    {
        $cacheKey = BulkDeleteJob::cacheKey("force:{$resource}");
        $progress = Cache::get($cacheKey);

        if (! $progress) {
            return response()->json(['status' => 'idle']);
        }

        return response()->json($progress);
    }

    /**
     * Удалить все записи ресурса — всегда через фоновую Job.
     * Возвращает мгновенный ответ с flash-сообщением.
     */
    public function destroyAll(Request $request, string $resource)
    {
        $registry = $this->registry();

        if (! isset($registry[$resource])) {
            abort(404, "Ресурс «{$resource}» не найден.");
        }

        $config = $registry[$resource];

        // Проверка прав
        if (! $request->user()->can($config['permission'])) {
            abort(403, 'Недостаточно прав для удаления.');
        }

        // Проверяем, не запущено ли уже удаление
        $cacheKey = BulkDeleteJob::cacheKey($resource);
        $progress = Cache::get($cacheKey);

        if ($progress && $progress['status'] === 'running') {
            return back()->with('warning', "Удаление «{$config['label']}» уже выполняется. Подождите завершения.");
        }

        // Диспатчим фоновую Job
        BulkDeleteJob::dispatch(
            $resource,
            $config['model'],
            $config['method'],
            $config['label']
        );

        return back()->with('success', "Удаление «{$config['label']}» запущено в фоне. Обновите страницу через несколько секунд.");
    }

    /**
     * Проверить статус фонового удаления.
     *
     * GET /admin/bulk-delete-status/{resource}
     */
    public function status(string $resource)
    {
        $cacheKey = BulkDeleteJob::cacheKey($resource);
        $progress = Cache::get($cacheKey);

        if (! $progress) {
            return response()->json(['status' => 'idle']);
        }

        return response()->json($progress);
    }
}
