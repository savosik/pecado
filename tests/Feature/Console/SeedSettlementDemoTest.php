<?php

namespace Tests\Feature\Console;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Organization;
use App\Models\PersonalManager;
use App\Models\SettlementEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Демо-данные регистра для dev-стенда (карточка fin-05, инструментальная часть).
 *
 * Главное свойство генератора — согласованность: балансы и контрольные точки
 * считаются ИЗ движений, а не рядом с ними. Проверяется оно единственным честным
 * способом — прогоном настоящей сверки поверх сгенерированного. Разъедься данные
 * хоть на копейку, `settlements:verify` вернёт ненулевой код, и тест упадёт.
 */
class SeedSettlementDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::factory()->create(['code' => 'RUB', 'is_base' => true, 'exchange_rate' => 1]);
        Organization::factory()->create(['is_stub' => false]);

        $card = PersonalManager::factory()->create(['user_id' => User::factory()->create()->id]);

        foreach (range(1, 3) as $i) {
            $client = User::factory()->create(['personal_manager_id' => $card->id]);
            Company::factory()->create(['user_id' => $client->id, 'erp_id' => (string) \Illuminate\Support\Str::uuid()]);
        }
    }

    #[Test]
    public function генератор_наполняет_регистр(): void
    {
        $this->artisan('settlements:demo --clients=3')->assertExitCode(0);

        $this->assertGreaterThan(0, SettlementEntry::query()->facts()->count());
        $this->assertGreaterThan(0, SettlementEntry::query()->plans()->count());
        $this->assertSame(
            0,
            SettlementEntry::query()->where('source', '!=', 'demo')->count(),
            'Все сгенерированные строки обязаны нести метку demo — по ней их и чистят.',
        );
    }

    /**
     * Ради этого генератор и писался: сверка на непустых данных обязана быть
     * зелёной ДО того, как приедет 1С. Иначе первое знакомство с инструментом
     * сведётся к разбору собственного мусора.
     */
    #[Test]
    public function сгенерированные_данные_проходят_сверку(): void
    {
        $this->artisan('settlements:demo --clients=3')->assertExitCode(0);

        $this->artisan('settlements:verify')->assertExitCode(0);
    }

    /**
     * Сравнивать количества нельзя: генератор случайный, и два прогона дают разное
     * число строк. Проверяем то, что действительно важно, — от первого прогона
     * не осталось ни одной строки, иначе стенд копил бы мусор с каждым запуском.
     */
    #[Test]
    public function повторный_прогон_с_fresh_не_копит_данные(): void
    {
        $this->artisan('settlements:demo --clients=3')->assertExitCode(0);
        $first = SettlementEntry::query()->pluck('uuid');

        $this->artisan('settlements:demo --clients=3 --fresh')->assertExitCode(0);

        $survived = SettlementEntry::query()->whereIn('uuid', $first)->count();

        $this->assertSame(0, $survived, 'Строки прошлого прогона обязаны быть удалены.');
        $this->assertGreaterThan(0, SettlementEntry::query()->count());
    }

    /**
     * На проде это боевые взаиморасчёты. Команда обязана отказаться сама,
     * а не полагаться на внимательность запускающего.
     */
    #[Test]
    public function на_проде_генератор_отказывается_работать(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('settlements:demo')->assertExitCode(1);

        $this->assertSame(0, SettlementEntry::query()->count());
    }
}
