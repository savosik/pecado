<?php

namespace Tests\Feature\Console;

use App\Enums\Country;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NormalizeCompaniesFromDaDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dadata.api_key' => 'test-api-key',
            'services.dadata.secret_key' => 'test-secret-key',
            'services.dadata.suggestions_url' => 'https://suggestions.dadata.ru/suggestions/api/4_1/rs',
            'services.dadata.cache_ttl' => 60,
        ]);

        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function sberbankPayload(): array
    {
        return [
            'suggestions' => [[
                'value' => 'ПАО СБЕРБАНК',
                'unrestricted_value' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО "СБЕРБАНК РОССИИ"',
                'data' => [
                    'inn' => '7707083893',
                    'kpp' => '773601001',
                    'ogrn' => '1027700132195',
                    'okpo' => '00032537',
                    'name' => [
                        'short_with_opf' => 'ПАО СБЕРБАНК',
                        'full_with_opf' => 'ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО "СБЕРБАНК РОССИИ"',
                    ],
                    'address' => [
                        'value' => '117312, г Москва, Академический р-н, ул Вавилова, д 19',
                        'unrestricted_value' => '117312, г Москва, Академический р-н, ул Вавилова, д 19',
                    ],
                ],
            ]],
        ];
    }

    public function test_fill_empty_заполняет_только_пустые_поля_и_не_перезаписывает_существующие(): void
    {
        Http::fake(['suggestions.dadata.ru/*' => Http::response($this->sberbankPayload(), 200)]);

        $company = Company::factory()->create([
            'country' => Country::RU,
            'tax_id' => '7707083893',
            'name' => 'Моё название',
            'legal_name' => '',
            'registration_number' => null,
            'tax_code' => '773601001',
            'okpo_code' => null,
            'legal_address' => '',
            'actual_address' => 'Существующий факт.адрес',
        ]);

        $this->artisan('companies:normalize-dadata', ['--inn' => ['7707083893']])
            ->assertExitCode(0);

        $company->refresh();

        $this->assertSame('Моё название', $company->name, 'Существующее name не должно было перезаписываться');
        $this->assertSame('ПУБЛИЧНОЕ АКЦИОНЕРНОЕ ОБЩЕСТВО "СБЕРБАНК РОССИИ"', $company->legal_name);
        $this->assertSame('1027700132195', $company->registration_number);
        $this->assertSame('00032537', $company->okpo_code);
        $this->assertSame('117312, г Москва, Академический р-н, ул Вавилова, д 19', $company->legal_address);
        $this->assertSame('Существующий факт.адрес', $company->actual_address, 'Существующий actual_address не перезаписан');
    }

    public function test_full_перезаписывает_все_поля(): void
    {
        Http::fake(['suggestions.dadata.ru/*' => Http::response($this->sberbankPayload(), 200)]);

        $company = Company::factory()->create([
            'country' => Country::RU,
            'tax_id' => '7707083893',
            'name' => 'Старое название',
            'legal_name' => 'Старое юр.название',
            'registration_number' => '0000000000000',
            'tax_code' => '773601001',
            'okpo_code' => '00000000',
            'legal_address' => 'Старый адрес',
            'actual_address' => 'Старый факт.адрес',
        ]);

        $this->artisan('companies:normalize-dadata', [
            '--inn' => ['7707083893'],
            '--mode' => 'full',
        ])->assertExitCode(0);

        $company->refresh();

        $this->assertSame('ПАО СБЕРБАНК', $company->name);
        $this->assertSame('1027700132195', $company->registration_number);
        $this->assertSame('117312, г Москва, Академический р-н, ул Вавилова, д 19', $company->legal_address);
        $this->assertSame('117312, г Москва, Академический р-н, ул Вавилова, д 19', $company->actual_address);
    }

    public function test_dry_run_не_пишет_в_бд(): void
    {
        Http::fake(['suggestions.dadata.ru/*' => Http::response($this->sberbankPayload(), 200)]);

        $company = Company::factory()->create([
            'country' => Country::RU,
            'tax_id' => '7707083893',
            'name' => 'Моё название',
            'legal_name' => '',
            'registration_number' => null,
        ]);

        $this->artisan('companies:normalize-dadata', [
            '--inn' => ['7707083893'],
            '--dry-run' => true,
        ])->assertExitCode(0);

        $company->refresh();
        $this->assertSame('', (string) $company->legal_name);
        $this->assertNull($company->registration_number);
    }

    public function test_невалидный_инн_пропускается(): void
    {
        Http::fake(['suggestions.dadata.ru/*' => Http::response($this->sberbankPayload(), 200)]);

        $company = Company::factory()->create([
            'country' => Country::RU,
            'tax_id' => 'abc',
            'tax_code' => '773601001',
            'name' => 'Какая-то компания',
        ]);

        $this->artisan('companies:normalize-dadata')
            ->assertExitCode(0);

        Http::assertNothingSent();
        $company->refresh();
        $this->assertSame('Какая-то компания', $company->name);
    }

    public function test_не_найденный_в_dadata_не_меняется(): void
    {
        Http::fake(['suggestions.dadata.ru/*' => Http::response(['suggestions' => []], 200)]);

        $company = Company::factory()->create([
            'country' => Country::RU,
            'tax_id' => '7777777771',
            'tax_code' => '773601001',
            'name' => 'Без изменений',
            'legal_name' => '',
        ]);

        $this->artisan('companies:normalize-dadata', ['--inn' => ['7777777771']])
            ->assertExitCode(0);

        $company->refresh();
        $this->assertSame('Без изменений', $company->name);
        $this->assertSame('', (string) $company->legal_name);
    }

    public function test_недопустимый_mode_возвращает_ошибку(): void
    {
        $this->artisan('companies:normalize-dadata', ['--mode' => 'wat'])
            ->assertExitCode(1);
    }
}
