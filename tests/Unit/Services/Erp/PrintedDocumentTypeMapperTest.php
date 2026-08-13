<?php

namespace Tests\Unit\Services\Erp;

use App\Enums\PrintedDocumentType;
use App\Services\Erp\Support\PrintedDocumentTypeMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Сопоставление вида печатной формы (v16.1.0).
 *
 * Контракт просит латинский код, но рассчитывать на это нельзя: разные
 * конфигурации 1С называют одни и те же формы по-разному, а неизвестный вид
 * не должен ронять документ — терять PDF нельзя, перезалить его неоткуда.
 */
class PrintedDocumentTypeMapperTest extends TestCase
{
    private PrintedDocumentTypeMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mapper = new PrintedDocumentTypeMapper;
    }

    #[Test]
    #[DataProvider('latinCodes')]
    public function latin_codes_map_exactly(string $code, PrintedDocumentType $expected): void
    {
        $this->assertSame($expected, $this->mapper->map($code));
    }

    public static function latinCodes(): array
    {
        return [
            ['contract', PrintedDocumentType::CONTRACT],
            ['invoice', PrintedDocumentType::INVOICE],
            ['tax_invoice', PrintedDocumentType::TAX_INVOICE],
            ['upd', PrintedDocumentType::UPD],
            ['reconciliation_act', PrintedDocumentType::RECONCILIATION_ACT],
            // Регистр не имеет значения: 1С выгружает коды как придётся.
            ['TAX_INVOICE', PrintedDocumentType::TAX_INVOICE],
        ];
    }

    #[Test]
    #[DataProvider('russianAliases')]
    public function russian_names_are_recognised(string $value, PrintedDocumentType $expected): void
    {
        $this->assertSame($expected, $this->mapper->map($value));
    }

    public static function russianAliases(): array
    {
        return [
            // «ё» и «е», дефисы, пробелы и слитное написание — всё это одно и то же.
            ['Счёт-фактура', PrintedDocumentType::TAX_INVOICE],
            ['СчетФактура', PrintedDocumentType::TAX_INVOICE],
            ['счет фактура выданный', PrintedDocumentType::TAX_INVOICE],
            ['Счёт на оплату клиенту', PrintedDocumentType::INVOICE],
            ['Акт сверки взаиморасчётов', PrintedDocumentType::RECONCILIATION_ACT],
            ['Универсальный передаточный документ', PrintedDocumentType::UPD],
            ['ТОРГ-12', PrintedDocumentType::WAYBILL],
            ['Договор с клиентом', PrintedDocumentType::CONTRACT],
        ];
    }

    #[Test]
    public function type_name_is_used_when_code_is_unknown(): void
    {
        // 1С прислала свой внутренний код, но название узнаваемое —
        // документ должен попасть в правильный раздел, а не в «Прочее».
        $this->assertSame(
            PrintedDocumentType::RECONCILIATION_ACT,
            $this->mapper->map('ВнутрКод_042', 'Акт сверки'),
        );
    }

    #[Test]
    #[DataProvider('unknownValues')]
    public function unknown_values_fall_back_to_other(?string $code, ?string $name): void
    {
        $this->assertSame(PrintedDocumentType::OTHER, $this->mapper->map($code, $name));
    }

    public static function unknownValues(): array
    {
        return [
            'оба пустые' => [null, null],
            'пустые строки' => ['', ''],
            'только пробелы' => ['   ', null],
            'мусор' => ['zzz-unknown-42', 'Какая-то форма'],
            'явный other' => ['other', null],
        ];
    }

    #[Test]
    public function options_cover_every_case(): void
    {
        // Фильтр в кабинете строится из options(): выпавший вид стал бы
        // документами, которые невозможно отобрать.
        $this->assertCount(count(PrintedDocumentType::cases()), PrintedDocumentType::options());

        foreach (PrintedDocumentType::options() as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('color', $option);
            $this->assertNotSame('', $option['label']);
        }
    }
}
