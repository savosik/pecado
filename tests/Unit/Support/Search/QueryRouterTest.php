<?php

namespace Tests\Unit\Support\Search;

use App\Support\Search\QueryRouter;
use PHPUnit\Framework\TestCase;

class QueryRouterTest extends TestCase
{
    /**
     * UUID — полный или фрагмент с буквой a-f либо дефисом.
     */
    public function test_classify_recognises_uuid_full_and_fragment(): void
    {
        $this->assertSame(
            QueryRouter::TYPE_UUID,
            QueryRouter::classify('7f3a8c12-1234-5678-9abc-def012345678'),
        );
        $this->assertSame(QueryRouter::TYPE_UUID, QueryRouter::classify('7f3a8c12'));
        $this->assertSame(QueryRouter::TYPE_UUID, QueryRouter::classify('7F3A8C12'));
        $this->assertSame(QueryRouter::TYPE_UUID, QueryRouter::classify('1234-5678'));
    }

    /**
     * C-1.6: 8/12/13/14 цифр — штрихкод (приоритет выше ИНН для 12 цифр).
     */
    public function test_classify_recognises_barcode(): void
    {
        $this->assertSame(QueryRouter::TYPE_BARCODE, QueryRouter::classify('4607123456789'));
        $this->assertSame(QueryRouter::TYPE_BARCODE, QueryRouter::classify('12345678'));
        $this->assertSame(QueryRouter::TYPE_BARCODE, QueryRouter::classify('123456789012'));
        $this->assertSame(QueryRouter::TYPE_BARCODE, QueryRouter::classify('12345678901234'));
    }

    /**
     * C-1.9: ИНН — 10 цифр (12-значный ИНН перебивается приоритетом штрихкода).
     */
    public function test_classify_recognises_tax_id(): void
    {
        $this->assertSame(QueryRouter::TYPE_TAX_ID, QueryRouter::classify('7707083893'));
    }

    /**
     * C-1.1: номер документа с кириллицей и дефисом.
     */
    public function test_classify_recognises_document_number_with_cyrillic_and_dash(): void
    {
        $this->assertSame(QueryRouter::TYPE_DOCUMENT_NUMBER, QueryRouter::classify('29УТ-003413'));
        $this->assertSame(QueryRouter::TYPE_DOCUMENT_NUMBER, QueryRouter::classify('29В-001245'));
        $this->assertSame(QueryRouter::TYPE_DOCUMENT_NUMBER, QueryRouter::classify('29ут-3413'));
    }

    /**
     * SKU-like — латиница + цифры + опциональный дефис, без пробелов и кириллицы.
     */
    public function test_classify_recognises_sku_like(): void
    {
        $this->assertSame(QueryRouter::TYPE_SKU_LIKE, QueryRouter::classify('AM90-001'));
        $this->assertSame(QueryRouter::TYPE_SKU_LIKE, QueryRouter::classify('AM90'));
        $this->assertSame(QueryRouter::TYPE_SKU_LIKE, QueryRouter::classify('zxy-12-34'));
    }

    /**
     * Свободный текст — кириллица без дефиса либо строки с пробелами.
     */
    public function test_classify_falls_back_to_text(): void
    {
        $this->assertSame(QueryRouter::TYPE_TEXT, QueryRouter::classify('Adidas Sport'));
        $this->assertSame(QueryRouter::TYPE_TEXT, QueryRouter::classify('кроссовки'));
        $this->assertSame(QueryRouter::TYPE_TEXT, QueryRouter::classify('air max 90'));
        $this->assertSame(QueryRouter::TYPE_TEXT, QueryRouter::classify('ООО Ромашка'));
    }

    public function test_classify_trims_input(): void
    {
        $this->assertSame(QueryRouter::TYPE_BARCODE, QueryRouter::classify('  4607123456789  '));
        $this->assertSame(QueryRouter::TYPE_TAX_ID, QueryRouter::classify("\t7707083893\n"));
    }

    public function test_classify_handles_empty_string_as_text(): void
    {
        $this->assertSame(QueryRouter::TYPE_TEXT, QueryRouter::classify(''));
        $this->assertSame(QueryRouter::TYPE_TEXT, QueryRouter::classify('   '));
    }
}
