<?php

namespace Tests\Unit\Pickup;

use App\Support\Erp\DocumentBarcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** pick-08: штрихкод печатной формы 1С — GUID документа как десятичное число. */
class DocumentBarcodeTest extends TestCase
{
    #[Test]
    public function known_values_convert_both_ways(): void
    {
        // Проверочные пары посчитаны независимо: int('<hex без дефисов>', 16) в Python.
        $pairs = [
            '00000000-0000-0000-0000-000000000001' => '1',
            '00000000-0000-0000-0000-0000000000ff' => '255',
            'ffffffff-ffff-ffff-ffff-ffffffffffff' => '340282366920938463463374607431768211455',
            '40301d16-3847-11e1-8034-001e6711ed1d' => '85320411929759447527444433964830420253',
            '5645311c-4885-11f1-8e85-86588116ffe9' => '114672872199644590084659120881729470441',
        ];

        foreach ($pairs as $guid => $decimal) {
            $this->assertSame($decimal, DocumentBarcode::fromGuid($guid), $guid);
            $this->assertSame($guid, DocumentBarcode::toGuid($decimal), $decimal);
        }
    }

    #[Test]
    public function round_trip_survives_leading_zeros_and_uppercase(): void
    {
        $guid = '000a3f00-0001-0000-8000-00000000abcd';

        $this->assertSame($guid, DocumentBarcode::toGuid(DocumentBarcode::fromGuid(strtoupper($guid))));
    }

    #[Test]
    public function scanner_whitespace_is_ignored(): void
    {
        $this->assertSame('00000000-0000-0000-0000-0000000000ff', DocumentBarcode::toGuid(" 255\r\n"));
    }

    #[Test]
    public function garbage_is_rejected(): void
    {
        $this->assertNull(DocumentBarcode::toGuid(''));
        $this->assertNull(DocumentBarcode::toGuid('29УТ-003413'));
        $this->assertNull(DocumentBarcode::toGuid('340282366920938463463374607431768211456'), '2^128 — за пределами GUID');
        $this->assertNull(DocumentBarcode::toGuid(str_repeat('9', 40)));
        $this->assertNull(DocumentBarcode::fromGuid('not-a-guid'));
    }
}
