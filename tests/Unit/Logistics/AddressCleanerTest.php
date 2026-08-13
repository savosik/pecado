<?php

namespace Tests\Unit\Logistics;

use App\Support\Logistics\AddressCleaner;
use PHPUnit\Framework\TestCase;

class AddressCleanerTest extends TestCase
{
    public function test_it_strips_delivery_instructions(): void
    {
        $this->assertSame(
            'г Самара ул Дзержинского д 48',
            AddressCleaner::tidy('г. Самара, ул. Дзержинского, д.48 ДО АДРЕСА, ЗА СЧЕТ КЛИЕНТА'),
        );
    }

    public function test_it_unwraps_brackets_where_the_real_address_hides(): void
    {
        $this->assertSame(
            'г Воронеж Московский пр-т 11',
            AddressCleaner::tidy('до пункта выдачи Яндекс.Маркет г. Воронеж (Московский пр-т, 11)'),
        );
    }

    public function test_query_variants_drop_the_noisy_tail(): void
    {
        $variants = AddressCleaner::queryVariants('Москва, ул. Правды, д. 24 стр. 2, КПП 6, этаж 1');

        $this->assertSame('Москва ул Правды д 24 стр 2 КПП 6 этаж 1', $variants[0]);
        $this->assertContains('Москва ул Правды д 24 стр 2', $variants);
    }

    public function test_it_keeps_details_inside_the_building(): void
    {
        $this->assertSame('офис 7, каб 6', AddressCleaner::details('Новочеремушкинская д.44, к.3, офис 7 каб 6, Москва'));
        $this->assertSame('', AddressCleaner::details('Москва, ул Иркутская, д 11'));
    }

    public function test_same_place_written_differently_gets_one_key(): void
    {
        $this->assertSame(
            AddressCleaner::key('Москва, ул. Иркутская, д. 11, к. 1'),
            AddressCleaner::key('москва Иркутская 11 корп 1'),
        );

        $this->assertSame(
            AddressCleaner::key('г. Самара, ул. Дзержинского, д.48 ДО АДРЕСА, ЗА СЧЕТ КЛИЕНТА'),
            AddressCleaner::key('г. Самара, ул. Дзержинского, д.48 за наш счет до адреса'),
        );
    }

    public function test_different_houses_keep_different_keys(): void
    {
        $this->assertNotSame(
            AddressCleaner::key('Москва, ул Дубнинская, д 32'),
            AddressCleaner::key('Москва, ул Дубнинская, д 36'),
        );
    }
}
