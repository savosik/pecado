<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Certificate;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DevDataCleanup extends Command
{
    protected $signature = 'dev:cleanup {--force : Пропустить подтверждение}';

    protected $description = '[DEV ONLY] Полная очистка данных: товары, бренды, категории, атрибуты, заказы, пользователи (кроме админов)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Команда запрещена в production!');
            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Удалить ВСЕ данные (товары, заказы, пользователей кроме админов)? Это необратимо!')) {
            $this->info('Отменено.');
            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $this->step('Заказы, доставки, возвраты', function () {
            DB::table('return_items')->truncate();
            DB::table('returns')->truncate();
            DB::table('shipment_items')->truncate();
            DB::table('shipments')->truncate();
            DB::table('order_change_logs')->truncate();
            DB::table('order_status_histories')->truncate();
            DB::table('order_items')->truncate();
            DB::table('orders')->truncate();
            DB::table('cart_items')->truncate();
            DB::table('carts')->truncate();
        });

        $this->step('Компании и балансы', function () {
            DB::table('contractor_balance_overdue_details')->truncate();
            DB::table('contractor_balances')->truncate();
            DB::table('company_bank_accounts')->truncate();
            DB::table('companies')->truncate();
        });

        $this->step('Пользователи (не-админы)', function () {
            $adminIds = DB::table('model_has_roles')
                ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
                ->where('roles.name', 'admin')
                ->pluck('model_has_roles.model_id');

            DB::table('favorites')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('wishlist_items')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('search_histories')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('user_questionnaires')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('api_tokens')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('social_accounts')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('delivery_addresses')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('product_exports')->whereNotIn('user_id', $adminIds)->delete();
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereNotIn('tokenable_id', $adminIds)
                ->delete();

            Media::where('model_type', User::class)
                ->whereNotIn('model_id', $adminIds)
                ->each(fn ($m) => $m->delete());

            User::whereNotIn('id', $adminIds)->delete();
        });

        $this->step('Pivot-таблицы товаров', function () {
            DB::table('product_attribute_values')->truncate();
            DB::table('product_barcodes')->truncate();
            DB::table('product_product_selection')->truncate();
            DB::table('product_promotion')->truncate();
            DB::table('product_warehouse')->truncate();
            DB::table('product_certificate')->truncate();
            DB::table('erp_promotion_product')->truncate();
            DB::table('taggables')->where('taggable_type', \App\Models\Product::class)->delete();
        });

        $this->step('Медиа товаров, брендов, категорий, сертификатов', function () {
            $types = [
                \App\Models\Product::class,
                Brand::class,
                Category::class,
                Certificate::class,
            ];
            Media::whereIn('model_type', $types)->each(fn ($m) => $m->delete());
        });

        $this->step('Товары', function () {
            DB::table('products')->truncate();
        });

        $this->step('Сертификаты', function () {
            DB::table('certificates')->truncate();
        });

        $this->step('Бренды', function () {
            DB::table('taggables')->where('taggable_type', Brand::class)->delete();
            DB::table('brand_size_chart')->truncate();
            DB::table('brand_stories')->truncate();
            DB::table('brands')->truncate();
        });

        $this->step('Категории', function () {
            DB::table('taggables')->where('taggable_type', Category::class)->delete();
            DB::table('attribute_category')->truncate();
            DB::table('categories')->truncate();
        });

        $this->step('Модели товаров', function () {
            DB::table('product_models')->truncate();
        });

        $this->step('Атрибуты и значения', function () {
            DB::table('attribute_values')->truncate();
            DB::table('attributes')->truncate();
            DB::table('attribute_groups')->truncate();
        });

        $this->step('Размерные сетки', function () {
            DB::table('size_charts')->truncate();
        });

        $this->step('Подборки товаров', function () {
            DB::table('product_selections')->truncate();
        });

        $this->step('ERP-продвижения', function () {
            DB::table('erp_promotions')->truncate();
        });

        $this->step('Индивидуальные цены (prices DB)', function () {
            DB::connection('prices')->table('individual_prices')->truncate();
        });

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->step('Scout-индексы (Meilisearch)', function () {
            try {
                \App\Models\Product::removeAllFromSearch();
                Category::removeAllFromSearch();
                Brand::removeAllFromSearch();
                Certificate::removeAllFromSearch();
            } catch (\Throwable $e) {
                $this->warn('Scout: '.$e->getMessage());
            }
        });

        $this->newLine();
        $this->info('Готово. Данные очищены.');

        return self::SUCCESS;
    }

    private function step(string $label, callable $fn): void
    {
        $this->line("  → {$label}...");
        $fn();
    }
}
