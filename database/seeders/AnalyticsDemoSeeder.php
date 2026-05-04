<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Демо-данные для дашборда «Аналитика» в личном кабинете.
 * Используется ТОЛЬКО локально для ручной проверки UI.
 *
 *   docker exec pecado-app php artisan db:seed --class=AnalyticsDemoSeeder
 *   docker exec pecado-app php artisan db:seed --class=AnalyticsDemoSeeder -- --user=admin@pecado.ru --count=400
 */
class AnalyticsDemoSeeder extends Seeder
{
    public function run(): void
    {
        $email = $this->option('--user', 'admin@pecado.ru');
        $count = (int) $this->option('--count', '400');

        $user = User::where('email', $email)->first();
        if (! $user) {
            $this->command?->error("Пользователь {$email} не найден.");

            return;
        }

        $this->command?->info("Сидинг аналитики для: {$user->email} (id={$user->id})");

        // 1. Контрагенты пользователя — добиваем до 6 штук.
        $companies = Company::withoutGlobalScopes()->where('user_id', $user->id)->get();
        $needCompanies = max(0, 6 - $companies->count());
        for ($i = 0; $i < $needCompanies; $i++) {
            $companies->push(Company::factory()->russia()->create([
                'user_id' => $user->id,
                'name' => $this->fakeCompanyName(),
            ]));
        }
        $this->command?->info("Контрагентов у пользователя: {$companies->count()}");

        // 2. Берём пул товаров — отдаём предпочтение тем, у кого есть бренд+категория.
        $productIds = Product::query()
            ->whereNotNull('brand_id')
            ->whereNotNull('category_id')
            ->inRandomOrder()
            ->limit(500)
            ->pluck('id')
            ->all();

        if (count($productIds) < 10) {
            // Fallback — любые товары
            $productIds = Product::query()->inRandomOrder()->limit(500)->pluck('id')->all();
        }
        $this->command?->info('Товаров в пуле: '.count($productIds));

        // 3. Создаём отгрузки за последний год с неравномерным распределением (последние месяцы плотнее).
        $now = CarbonImmutable::now();
        $totalCreated = 0;
        $statuses = ['completed', 'completed', 'completed', 'in_progress', 'new'];
        $currencies = ['RUB', 'RUB', 'RUB', 'RUB', 'BYN', 'KZT']; // RUB чаще

        for ($i = 0; $i < $count; $i++) {
            // Дата: половина в текущем месяце, четверть в предыдущем, четверть в более старом периоде.
            $r = mt_rand(1, 100);
            if ($r <= 50) {
                $date = $now->subDays(mt_rand(0, $now->day - 1));
            } elseif ($r <= 75) {
                $date = $now->subMonth()->startOfMonth()->addDays(mt_rand(0, 27));
            } else {
                $date = $now->subDays(mt_rand(60, 360));
            }

            $company = $companies->random();
            $itemCount = mt_rand(3, 12);
            $shipment = Shipment::create([
                'uuid' => (string) Str::uuid(),
                'erp_number' => 'DEMO-'.Str::upper(Str::random(6)),
                'user_id' => $user->id,
                'company_id' => $company->id,
                'tax_id' => $company->tax_id,
                'date' => $date->toDateString(),
                'status' => $statuses[array_rand($statuses)],
                'currency_code' => $currencies[array_rand($currencies)],
                'total_amount' => 0,
            ]);

            $sum = 0;
            $usedProducts = [];
            for ($j = 0; $j < $itemCount; $j++) {
                $pid = $productIds[array_rand($productIds)];
                if (in_array($pid, $usedProducts, true)) {
                    continue;
                }
                $usedProducts[] = $pid;

                $qty = mt_rand(1, 25);
                $price = mt_rand(50, 5000) + (mt_rand(0, 99) / 100);
                $autoDisc = mt_rand(0, 4) === 0 ? mt_rand(2, 15) : 0;
                $subtotal = round($qty * $price, 2);
                $total = round($subtotal * (1 - $autoDisc / 100), 2);
                $sum += $total;

                ShipmentItem::create([
                    'shipment_id' => $shipment->id,
                    'product_id' => $pid,
                    'quantity' => $qty,
                    'price' => $price,
                    'auto_discount_percent' => $autoDisc,
                    'manual_discount_percent' => 0,
                    'subtotal' => $subtotal,
                    'total' => $total,
                    'vat_rate' => 20,
                ]);
            }

            $shipment->update(['total_amount' => $sum]);
            $totalCreated++;

            if ($totalCreated % 50 === 0) {
                $this->command?->info("  создано {$totalCreated}/{$count}…");
            }
        }

        $this->command?->info("Готово. Создано {$totalCreated} отгрузок для {$user->email}.");
        $this->command?->info('Открой /cabinet/analytics под этим пользователем.');
    }

    private function option(string $key, string $default): string
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (str_starts_with($arg, $key.'=')) {
                return substr($arg, strlen($key) + 1);
            }
        }

        return $default;
    }

    private function fakeCompanyName(): string
    {
        $forms = ['ООО', 'ИП', 'ЗАО', 'ЧТУП'];
        $words = ['Ромашка', 'Восток', 'Премиум', 'Альфа', 'Меридиан', 'Континент', 'Стандарт', 'Капитал', 'Форум', 'Партнёр'];
        $cities = ['г. Москва', 'г. Минск', 'г. Гомель', 'г. Витебск', 'г. Барановичи', 'г. Брест', 'г. Алматы'];

        return $forms[array_rand($forms)].' "'.$words[array_rand($words)].'", '.$cities[array_rand($cities)];
    }
}
