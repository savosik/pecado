<?php

namespace Tests\Unit\Support\Search;

use App\Support\Search\DocumentNumber;
use PHPUnit\Framework\TestCase;

class DocumentNumberTest extends TestCase
{
    /**
     * § «Сквозные принципы» п.1: регистр и пробелы схлопываются.
     */
    public function test_normalize_lowercases_and_strips_whitespace(): void
    {
        $this->assertSame('adidassport', DocumentNumber::normalize('  Adidas   Sport  '));
        $this->assertSame('adidassport', DocumentNumber::normalize('adidas sport'));
        $this->assertSame('29ут003413', DocumentNumber::normalize('29УТ 003413'));
    }

    /**
     * § «Сквозные принципы» п.2 / C-1.1: дефисы в номерах документов схлопываются,
     * `29УТ-003413` ≡ `29УТ003413`.
     */
    public function test_normalize_strips_hyphens_in_document_numbers(): void
    {
        $expected = '29ут003413';

        $this->assertSame($expected, DocumentNumber::normalize('29УТ-003413'));
        $this->assertSame($expected, DocumentNumber::normalize('29УТ003413'));
        $this->assertSame($expected, DocumentNumber::normalize('29ут-003413'));
        $this->assertSame($expected, DocumentNumber::normalize('  29 УТ - 003413  '));
    }

    public function test_normalize_keeps_only_payload_for_short_query(): void
    {
        $this->assertSame('003413', DocumentNumber::normalize('003413'));
        $this->assertSame('003413', DocumentNumber::normalize('  003413  '));
    }

    public function test_normalize_handles_empty_string(): void
    {
        $this->assertSame('', DocumentNumber::normalize(''));
        $this->assertSame('', DocumentNumber::normalize('   '));
    }

    /**
     * C-1.6: штрихкоды — строго 8/12/13/14 цифр.
     */
    public function test_is_likely_barcode_accepts_ean_lengths(): void
    {
        $this->assertTrue(DocumentNumber::isLikelyBarcode('12345678'));
        $this->assertTrue(DocumentNumber::isLikelyBarcode('123456789012'));
        $this->assertTrue(DocumentNumber::isLikelyBarcode('4607123456789'));
        $this->assertTrue(DocumentNumber::isLikelyBarcode('12345678901234'));
    }

    public function test_is_likely_barcode_rejects_other_lengths_and_non_digits(): void
    {
        $this->assertFalse(DocumentNumber::isLikelyBarcode('1234567'));
        $this->assertFalse(DocumentNumber::isLikelyBarcode('123456789'));
        $this->assertFalse(DocumentNumber::isLikelyBarcode('12345678901'));
        $this->assertFalse(DocumentNumber::isLikelyBarcode('1234567890123456'));
        $this->assertFalse(DocumentNumber::isLikelyBarcode('4607-12345-6789'));
        $this->assertFalse(DocumentNumber::isLikelyBarcode('AM90-001'));
        $this->assertFalse(DocumentNumber::isLikelyBarcode(''));
    }

    /**
     * C-1.9: ИНН — строго 10 или 12 цифр.
     */
    public function test_is_likely_tax_id_accepts_legal_and_individual_lengths(): void
    {
        $this->assertTrue(DocumentNumber::isLikelyTaxId('7707083893'));
        $this->assertTrue(DocumentNumber::isLikelyTaxId('770708389312'));
    }

    public function test_is_likely_tax_id_rejects_other_lengths(): void
    {
        $this->assertFalse(DocumentNumber::isLikelyTaxId('123456789'));
        $this->assertFalse(DocumentNumber::isLikelyTaxId('12345678901'));
        $this->assertFalse(DocumentNumber::isLikelyTaxId('1234567890123'));
        $this->assertFalse(DocumentNumber::isLikelyTaxId('770708389a'));
        $this->assertFalse(DocumentNumber::isLikelyTaxId(''));
    }
}
