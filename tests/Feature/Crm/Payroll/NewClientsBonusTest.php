<?php

namespace Tests\Feature\Crm\Payroll;

use App\Models\PayrollScheme;
use App\Models\PersonalManager;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\Payroll\Dto\PayrollInputs;
use App\Services\Payroll\PayrollCalculator;
use App\Services\Payroll\PayrollInputCollector;
use App\Services\Payroll\PayrollParamsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class NewClientsBonusTest extends TestCase
{
    use RefreshDatabase;

    private PersonalManager $manager;

    private Carbon $month;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->manager = PersonalManager::factory()->create();
        $this->month = Carbon::now()->startOfMonth();
    }

    private function client(string $name): User
    {
        return User::factory()->create(['personal_manager_id' => $this->manager->id, 'name' => $name]);
    }

    private function ship(User $client, float $total, Carbon $date): void
    {
        $shipment = Shipment::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $client->id,
            'date' => $date->toDateString(),
            'erp_created_at' => $date,
            'status' => 'completed',
            'currency_code' => 'RUB',
            'total_amount' => $total,
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id, 'product_id' => Product::factory()->create()->id,
            'quantity' => 1, 'price' => $total, 'total' => $total, 'subtotal' => $total,
        ]);
    }

    /**
     * Схема с включённым компонентом и цифрами из эпика.
     */
    private function paramsWithBonus(): \App\Services\Payroll\Dto\EffectiveParams
    {
        $components = config('payroll.default_scheme.components');
        foreach ($components as &$entry) {
            if ($entry['key'] === 'new_clients_bonus') {
                $entry['enabled'] = true;
                $entry['defaults'] = ['bonus' => 2000, 'min_first_amount' => 10000, 'repeat_within_days' => 60, 'monthly_cap' => 5000, 'returned_weight' => 0.5, 'returned_after_days' => 90];
            }
        }

        return app(PayrollParamsResolver::class)->fromScheme(new PayrollScheme(['components' => $components]));
    }

    #[Test]
    #[TestDox('Коллектор находит новых, повторивших и вернувшихся; компонент платит половины, вес и потолок')]
    public function collects_and_pays(): void
    {
        $m = $this->month;

        $fresh = $this->client('Новый');                 // первая отгрузка в этом месяце, 15 000
        $this->ship($fresh, 15000, $m->copy()->addDays(3));

        $tiny = $this->client('Тестовый');               // первая отгрузка ниже порога
        $this->ship($tiny, 800, $m->copy()->addDays(4));

        $repeat = $this->client('Повтор');               // первая — 20 дней назад, вторая — сейчас
        $this->ship($repeat, 30000, $m->copy()->subDays(20));
        $this->ship($repeat, 12000, $m->copy()->addDays(5));

        $lateRepeat = $this->client('Поздний повтор');   // вторая через 100 дней — не повтор
        $this->ship($lateRepeat, 30000, $m->copy()->subDays(100));
        $this->ship($lateRepeat, 12000, $m->copy()->addDays(6));

        $returned = $this->client('Вернулся');           // покупал давно, пауза 200 дней
        $this->ship($returned, 5000, $m->copy()->subDays(400));
        $this->ship($returned, 7000, $m->copy()->subDays(200));
        $this->ship($returned, 9000, $m->copy()->addDays(7));

        $regular = $this->client('Постоянный');          // давняя история, пауза 20 дней — ничего
        $this->ship($regular, 5000, $m->copy()->subDays(300));
        $this->ship($regular, 5000, $m->copy()->subDays(20));
        $this->ship($regular, 5000, $m->copy()->addDays(8));

        $params = $this->paramsWithBonus();
        $inputs = app(PayrollInputCollector::class)->collect($this->manager->id, $m, $params->for('new_clients_bonus'));

        $byName = [];
        foreach ($inputs->newClients as $row) {
            $byName[$row['name']] = $row;
        }

        $this->assertSame(['Новый', 'Тестовый', 'Повтор', 'Поздний повтор', 'Вернулся'], array_keys($byName));
        $this->assertSame('new', $byName['Новый']['kind']);
        $this->assertSame('first', $byName['Новый']['stage']);
        $this->assertSame(15000.0, $byName['Новый']['first_amount']);
        $this->assertSame('repeat', $byName['Повтор']['stage']);
        $this->assertSame(25, $byName['Повтор']['repeat_after_days']);   // 20 дней до начала месяца + 5 внутри
        // «Поздний повтор»: вторая отгрузка позже срока повтора, но пауза 100 дней > 90 — это вернувшийся.
        $this->assertSame('returned', $byName['Поздний повтор']['kind']);
        $this->assertSame('returned', $byName['Вернулся']['kind']);
        $this->assertSame(207, $byName['Вернулся']['gap_days']);   // 200 дней до начала месяца + 7 внутри

        $breakdown = app(PayrollCalculator::class)->calculate($params, $inputs);
        $component = $breakdown->component('new_clients_bonus');

        // Новый 1 000 + повтор 1 000 + вернувшиеся 2 × 1 000 = 4 000; тестовый — 0.
        $this->assertSame(4000.0, $component->amount);
        $this->assertSame(1, $component->meta['below_min']);
        $this->assertFalse($component->meta['capped']);
        $this->assertStringContainsString('новых 1', $component->explanation);

        $this->assertSame(70000.0 + 4000.0, $breakdown->total);
    }

    #[Test]
    #[TestDox('Потолок за месяц режет сумму; выключенный в схеме компонент не участвует')]
    public function cap_and_disabled(): void
    {
        $params = $this->paramsWithBonus();
        $inputs = new PayrollInputs(
            managerId: 1, month: $this->month->toDateString(), plan: null, revenue: 0.0,
            newClients: array_map(fn (int $i): array => ['id' => $i, 'name' => "n{$i}", 'kind' => 'new', 'stage' => 'first', 'first_amount' => 50000], range(1, 8)),
        );

        $component = app(PayrollCalculator::class)->calculate($params, $inputs)->component('new_clients_bonus');
        $this->assertSame(5000.0, $component->amount);   // 8 × 1 000 = 8 000 → потолок 5 000
        $this->assertTrue($component->meta['capped']);

        $default = app(PayrollParamsResolver::class)->fromScheme(new PayrollScheme(['components' => config('payroll.default_scheme.components')]));
        $this->assertNull(app(PayrollCalculator::class)->calculate($default, $inputs)->component('new_clients_bonus'));
        $this->assertFalse($default->enabled('new_clients_bonus'));
    }
}
