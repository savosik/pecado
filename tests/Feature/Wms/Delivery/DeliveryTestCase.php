<?php

namespace Tests\Feature\Wms\Delivery;

use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Общая обвязка тестов отправок: роли склада, включённая интеграция и заглушки ApiShip.
 *
 * Конфиг задаём через config()->set, а не через .env: тесты не должны зависеть
 * от того, что лежит в окружении разработчика.
 */
abstract class DeliveryTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        config()->set('services.apiship.enabled', true);
        config()->set('services.apiship.base_url', 'https://api.apiship.test/v1');
        // Явно гасим готовый токен: в .env разработчика он может быть задан,
        // и тогда тесты авторизации по логину молча проверяли бы не ту ветку.
        config()->set('services.apiship.token', null);
        config()->set('services.apiship.login', 'test-login');
        config()->set('services.apiship.password', 'test-password');
        config()->set('services.apiship.calculator_cache_ttl', 0);
        config()->set('services.apiship.sender', [
            'company_name' => 'Pecado',
            'contact_name' => 'Кладовщик',
            'phone' => '+79111111111',
            'country_code' => 'RU',
            'region' => 'Тюменская обл',
            'city' => 'Тюмень',
            'street' => 'ул Складская',
            'house' => '1',
            'index' => '625000',
        ]);
        config()->set('services.apiship.defaults', [
            'item_weight_grams' => 500,
            'place_length' => 40,
            'place_width' => 30,
            'place_height' => 20,
        ]);
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    /**
     * Реализация из 1С с одной позицией известного веса.
     *
     * @param  float|null  $weightKg  вес товара в килограммах; null — товар без веса
     */
    protected function makeShipment(?User $client = null, ?float $weightKg = 1.5, int $quantity = 2): Shipment
    {
        $client ??= User::factory()->create();

        $shipment = Shipment::factory()->create([
            'user_id' => $client->id,
            'erp_number' => 'РЕА-'.fake()->unique()->numberBetween(1000, 9999),
            'total_amount' => 12000,
        ]);

        $product = Product::factory()->create(['weight_gross' => $weightKg]);

        ShipmentItem::factory()->create([
            'shipment_id' => $shipment->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'quantity' => $quantity,
            'price' => 6000,
            // Со скидкой: опись строится по total, а не по цене до скидок.
            'subtotal' => 6000 * $quantity,
            'total' => 5800 * $quantity,
        ]);

        return $shipment->fresh();
    }

    /**
     * Добавить отправке грузовое место так же, как это делает мастер: вместе
     * с местом обновляются счётчик мест и заявленный вес.
     */
    protected function addPlace(
        \App\Models\Delivery\DeliveryShipment $delivery,
        int $weight = 3200,
        ?int $length = 40,
        ?int $width = 30,
        ?int $height = 20,
    ): void {
        $delivery->places()->create([
            'number' => $delivery->places()->count() + 1,
            'weight' => $weight,
            'length' => $length,
            'width' => $width,
            'height' => $height,
        ]);

        $delivery->forceFill([
            'places_count' => $delivery->places()->count(),
            'declared_weight' => (int) $delivery->places()->sum('weight'),
        ])->save();
    }

    /**
     * Inertia-страница как JSON.
     *
     * Без заголовка X-Inertia контроллер отдаёт HTML-обёртку, и до пропсов
     * в тесте не добраться.
     */
    protected function inertiaProps(string $url): array
    {
        // Читаем пропсы из данных корневого шаблона, а не из JSON-ответа Inertia:
        // JSON требует совпадения X-Inertia-Version, и на расхождении тест падал
        // бы с 409 вместо того, что он проверяет.
        $response = $this->get($url);
        $response->assertOk();

        return $response->viewData('page')['props'] ?? [];
    }

    /**
     * Заглушка логина: без неё любой вызов упрётся в отсутствие токена.
     *
     * @param  array<string, mixed>  $routes  дополнительные маршруты вида 'url-pattern' => Http::response(...)
     */
    protected function fakeApiShip(array $routes = []): void
    {
        Http::fake(array_merge([
            '*/login' => Http::response(['token' => 'test-token'], 200),
        ], $routes));
    }

    /**
     * Тело запроса, ушедшего на указанный путь.
     *
     * @return array<string, mixed>|null
     */
    protected function sentPayload(string $needle): ?array
    {
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), $needle)) {
                return $request->data();
            }
        }

        return null;
    }
}
