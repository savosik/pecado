<?php

namespace Tests\Unit\Payroll;

use App\Services\Payroll\Invoices\InvoiceNumberNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

class InvoiceNumberNormalizerTest extends TestCase
{
    private InvoiceNumberNormalizer $numbers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->numbers = new InvoiceNumberNormalizer;
    }

    #[Test]
    #[TestDox('Ведущие нули и написание префикса не влияют на ключ')]
    public function key_ignores_leading_zeros_and_lookalikes(): void
    {
        $this->assertSame('29YT-7699', $this->numbers->key('29УТ-007699'));
        $this->assertSame('29YT-7699', $this->numbers->key('29УТ-7699'));
        $this->assertSame('29YT-7699', $this->numbers->key(' 29ут-0007699 '));
        $this->assertSame('A2YT-768', $this->numbers->key('A2УТ-000768'));   // латинская A
        $this->assertSame('A2YT-768', $this->numbers->key('А2УТ-000768'));   // кириллическая А
    }

    #[Test]
    #[TestDox('Мусор и пустота дают null')]
    public function garbage_gives_null(): void
    {
        $this->assertNull($this->numbers->key(null));
        $this->assertNull($this->numbers->key(''));
        $this->assertNull($this->numbers->key('без номера'));
        $this->assertNull($this->numbers->key('29УТ'));
    }

    #[Test]
    #[TestDox('Из имени объекта расчётов берётся номер только у реализации')]
    public function object_name_yields_key_only_for_shipments(): void
    {
        $this->assertSame(
            '29YT-7699',
            $this->numbers->fromObjectName('Реализация товаров и услуг 29УТ-007699 от 26.08.2026 15:54:05'),
        );
        $this->assertSame(
            'A2YT-768',
            $this->numbers->fromObjectName('Реализация товаров и услуг А2УТ-000768 от 28.08.2026 10:17:44'),
        );

        // Аванс по заказу и сам платёжный документ — не накладные.
        $this->assertNull($this->numbers->fromObjectName('Заказ клиента 29УТ-004321 от 01.07.2026 10:00:00'));
        $this->assertNull($this->numbers->fromObjectName('Поступление безналичных ДС 29УТ-002781 от 26.08.2026 23:59:59'));
        $this->assertNull($this->numbers->fromObjectName(null));
        $this->assertNull($this->numbers->fromObjectName('Реализация без номера'));
    }
}
