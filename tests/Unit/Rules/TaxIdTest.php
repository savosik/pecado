<?php

namespace Tests\Unit\Rules;

use App\Rules\TaxId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxIdTest extends TestCase
{
    private function check(string $country, ?string $value): ?string
    {
        $rule = new TaxId($country);
        $error = null;
        $rule->validate('tax_id', $value, function (string $message) use (&$error) {
            $error = $message;
        });

        return $error;
    }

    #[Test]
    public function empty_value_fails(): void
    {
        $this->assertNotNull($this->check('RU', ''));
        $this->assertNotNull($this->check('RU', null));
    }

    #[Test]
    #[DataProvider('validRu')]
    public function valid_russian_inns_pass(string $value): void
    {
        $this->assertNull($this->check('RU', $value));
    }

    public static function validRu(): array
    {
        return [
            ['7707083893'],
            ['7710140679'],
            ['500100732259'],
        ];
    }

    #[Test]
    #[DataProvider('invalidRu')]
    public function invalid_russian_inns_fail(string $value): void
    {
        $this->assertNotNull($this->check('RU', $value));
    }

    public static function invalidRu(): array
    {
        return [
            'too_short' => ['123456789'],
            'eleven_digits' => ['12345678901'],
            'with_letters' => ['77070838AB'],
            'wrong_checksum_10' => ['7707083894'],
            'wrong_checksum_12' => ['500100732250'],
        ];
    }

    #[Test]
    #[DataProvider('validBy')]
    public function valid_by_unp_pass(string $value): void
    {
        $this->assertNull($this->check('BY', $value));
    }

    public static function validBy(): array
    {
        return [
            ['100000001'],
            ['190190190'],
        ];
    }

    #[Test]
    public function invalid_by_unp_fails(): void
    {
        $this->assertNotNull($this->check('BY', '12345678'));
        $this->assertNotNull($this->check('BY', '1234567890'));
        $this->assertNotNull($this->check('BY', '12345678A'));
    }

    #[Test]
    #[DataProvider('validKz')]
    public function valid_kz_bin_pass(string $value): void
    {
        $this->assertNull($this->check('KZ', $value));
    }

    public static function validKz(): array
    {
        return [
            ['940140000385'],
            ['060000000001'],
        ];
    }

    #[Test]
    public function invalid_kz_bin_fails(): void
    {
        $this->assertNotNull($this->check('KZ', '12345678901'));
        $this->assertNotNull($this->check('KZ', '940140000386'));
        $this->assertNotNull($this->check('KZ', '94014000038A'));
    }

    #[Test]
    public function generic_country_accepts_alphanumeric(): void
    {
        $this->assertNull($this->check('UZ', 'TIN-12345'));
        $this->assertNotNull($this->check('UZ', '!!'));
    }
}
