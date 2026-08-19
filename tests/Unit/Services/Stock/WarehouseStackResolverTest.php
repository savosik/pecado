<?php

namespace Tests\Unit\Services\Stock;

use App\Services\Stock\WarehouseStackResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Чистый резолвер стопки: строгое замещение, а не сумма.
 */
class WarehouseStackResolverTest extends TestCase
{
    #[Test]
    public function верхний_склад_с_наличием_замещает_нижние(): void
    {
        $resolved = (new WarehouseStackResolver)->resolve(
            [10, 20, 30],
            [1 => [10 => 5, 20 => 100, 30 => 7]],
        );

        $this->assertSame(['warehouse_id' => 10, 'quantity' => 5], $resolved[1]);
    }

    #[Test]
    public function без_наличия_наверху_действует_фолбэк_на_нижний_склад(): void
    {
        $resolved = (new WarehouseStackResolver)->resolve(
            [10, 20, 30],
            [
                1 => [10 => 0, 20 => 0, 30 => 7],
                2 => [20 => 3],
            ],
        );

        $this->assertSame(['warehouse_id' => 30, 'quantity' => 7], $resolved[1]);
        $this->assertSame(['warehouse_id' => 20, 'quantity' => 3], $resolved[2]);
    }

    #[Test]
    public function нули_на_всех_складах_дают_null_и_ноль(): void
    {
        $resolved = (new WarehouseStackResolver)->resolve(
            [10, 20],
            [1 => [10 => 0, 20 => 0]],
        );

        $this->assertSame(['warehouse_id' => null, 'quantity' => 0], $resolved[1]);
    }

    #[Test]
    public function пустая_стопка_даёт_null_для_каждого_товара(): void
    {
        $resolved = (new WarehouseStackResolver)->resolve([], [1 => [10 => 5]]);

        $this->assertSame(['warehouse_id' => null, 'quantity' => 0], $resolved[1]);
    }

    #[Test]
    public function склады_вне_стопки_не_участвуют(): void
    {
        $resolved = (new WarehouseStackResolver)->resolve(
            [10],
            [1 => [99 => 500, 10 => 2]],
        );

        $this->assertSame(['warehouse_id' => 10, 'quantity' => 2], $resolved[1]);
    }
}
