<?php

namespace Tests\Unit\Logistics;

use App\Support\Logistics\CarrierDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CarrierDetectorTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function cases(): array
    {
        return [
            'курьерская доставка клиенту' => ['доставка', 'Москва, ул Иркутская, д. 11', null],
            'доставка со временем' => ['доставка до 16:00', 'МО, г.Балашиха, ул Советская, д. 47', null],
            'перевозчик в типе доставки' => ['ТК Деловые линии', 'терминал в г.Самара', 'Деловые линии'],
            'перевозчик строчными' => ['тк деловые линии', 'терминал г. Рязань', 'Деловые линии'],
            'СДЭК' => ['ТК СДЭК', 'Казань', 'СДЭК'],
            'ПЭК' => ['ТК ПЭК', 'терминал Уфа', 'ПЭК'],
            'терминал без названия ТК' => ['доставка', 'терминал г. Вологда', 'ТК'],
            'фамилия с «кит» внутри — не перевозчик' => ['доставка', 'Москва, ул Никитинская, д. 5', null],
            'улица с «дл» внутри — не перевозчик' => ['доставка', 'Москва, ул Подлесная, д. 2', null],
        ];
    }

    #[DataProvider('cases')]
    public function test_it_detects_carrier_terminals(string $delivery, string $address, ?string $expected): void
    {
        $this->assertSame($expected, CarrierDetector::detect($delivery, $address));
    }

    public function test_it_detects_self_pickup(): void
    {
        $this->assertTrue(CarrierDetector::isSelfPickup('самовывоз', 'Тюмень'));
        $this->assertTrue(CarrierDetector::isSelfPickup('', 'самовывоз со склада'));
        $this->assertFalse(CarrierDetector::isSelfPickup('доставка', 'Москва, ул Иркутская, д. 11'));
    }
}
