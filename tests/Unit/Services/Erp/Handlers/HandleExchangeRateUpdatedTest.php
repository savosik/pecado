<?php

namespace Tests\Unit\Services\Erp\Handlers;

use App\Models\Currency;
use App\Services\Erp\Handlers\HandleExchangeRateUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HandleExchangeRateUpdatedTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_updates_currency_exchange_rate(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'KZT',
            'official_rate' => null,
            'rate_coefficient' => 1.0,
            'exchange_rate' => 5.00,
            'exchange_rate_date' => null,
        ]);

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.40,
            'rate_coefficient' => 1.01,
            'rate' => 5.45,
            'base_currency_code' => 'RUB',
            'date' => '2026-02-16',
        ]);

        $currency->refresh();

        $this->assertEquals(5.40, (float) $currency->official_rate);
        $this->assertEquals(1.01, (float) $currency->rate_coefficient);
        $this->assertEquals(5.45, (float) $currency->exchange_rate);
        $this->assertEquals('2026-02-16', $currency->exchange_rate_date->format('Y-m-d'));
    }

    #[Test]
    public function it_updates_rate_without_official_rate(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'BYN',
            'official_rate' => null,
            'rate_coefficient' => 1.0,
            'exchange_rate' => 30.00,
        ]);

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'BYN',
            'rate' => 28.50,
            'base_currency_code' => 'RUB',
        ]);

        $currency->refresh();

        // official_rate и rate_coefficient не изменились — не было в payload
        $this->assertNull($currency->official_rate);
        $this->assertEquals(1.0, (float) $currency->rate_coefficient);
        $this->assertEquals(28.50, (float) $currency->exchange_rate);
    }

    #[Test]
    public function it_saves_official_rate_and_rate_coefficient(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'KZT',
            'official_rate' => null,
            'rate_coefficient' => 1.0,
            'exchange_rate' => 5.00,
        ]);

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.40,
            'rate_coefficient' => 1.01,
            'rate' => 5.4540,
            'base_currency_code' => 'RUB',
            'date' => '2026-02-16',
        ]);

        $currency->refresh();

        $this->assertEquals(5.40, (float) $currency->official_rate, 'official_rate должен быть сохранён из payload');
        $this->assertEquals(1.01, (float) $currency->rate_coefficient, 'rate_coefficient должен обновиться из rate_coefficient');
        $this->assertEquals(5.4540, (float) $currency->exchange_rate, 'exchange_rate — итоговый курс из payload[rate]');
    }

    #[Test]
    public function it_updates_rate_without_date(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'BYN',
            'exchange_rate' => 30.00,
        ]);

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'BYN',
            'rate' => 28.50,
            'base_currency_code' => 'RUB',
        ]);

        $currency->refresh();

        $this->assertEquals(28.50, (float) $currency->exchange_rate);
    }

    #[Test]
    public function it_ignores_unknown_currency_without_error(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'валюта не найдена по коду');
            });

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'USD',
            'official_rate' => 90.00,
            'rate_coefficient' => 1.0,
            'rate' => 90.00,
            'base_currency_code' => 'RUB',
            'date' => '2026-02-16',
        ]);

        // Не должно быть ошибки — просто игнорируем
    }

    #[Test]
    public function it_does_nothing_when_currency_code_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует currency_code или rate');
            });

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'rate' => 5.45,
        ]);
    }

    #[Test]
    public function it_does_nothing_when_rate_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($msg) {
                return str_contains($msg, 'отсутствует currency_code или rate');
            });

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
        ]);
    }

    #[Test]
    public function it_overwrites_existing_rate(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'KZT',
            'official_rate' => 5.00,
            'rate_coefficient' => 1.0,
            'exchange_rate' => 5.00,
            'exchange_rate_date' => '2026-01-01',
        ]);

        $handler = new HandleExchangeRateUpdated;

        // Первое обновление
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.40,
            'rate_coefficient' => 1.01,
            'rate' => 5.45,
            'date' => '2026-02-16',
        ]);

        $currency->refresh();
        $this->assertEquals(5.40, (float) $currency->official_rate);
        $this->assertEquals(1.01, (float) $currency->rate_coefficient);
        $this->assertEquals(5.45, (float) $currency->exchange_rate);
        $this->assertEquals('2026-02-16', $currency->exchange_rate_date->format('Y-m-d'));

        // Второе обновление — перезаписывает без истории
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 5.54,
            'rate_coefficient' => 1.011,
            'rate' => 5.60,
            'date' => '2026-03-01',
        ]);

        $currency->refresh();
        $this->assertEquals(5.54, (float) $currency->official_rate);
        $this->assertEquals(1.011, (float) $currency->rate_coefficient);
        $this->assertEquals(5.60, (float) $currency->exchange_rate);
        $this->assertEquals('2026-03-01', $currency->exchange_rate_date->format('Y-m-d'));
    }

    #[Test]
    public function it_updates_rate_to_zero(): void
    {
        $currency = Currency::factory()->create([
            'code' => 'KZT',
            'official_rate' => 5.00,
            'exchange_rate' => 5.00,
        ]);

        $handler = new HandleExchangeRateUpdated;
        $handler->handle([
            'event' => 'exchange_rate.updated',
            'currency_code' => 'KZT',
            'official_rate' => 0,
            'rate_coefficient' => 1.0,
            'rate' => 0,
            'date' => '2026-02-16',
        ]);

        $currency->refresh();
        $this->assertEquals(0, (float) $currency->exchange_rate);
        $this->assertEquals(0, (float) $currency->official_rate);
    }
}
