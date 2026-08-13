<?php

namespace Tests\Feature\Logistics;

use App\Models\Company;
use App\Models\DeliveryAddress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportLogisticsAddressesTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.dadata.api_key', 'test-key');
        config()->set('services.dadata.suggestions_url', 'https://suggestions.dadata.ru/suggestions/api/4_1/rs');

        $this->file = tempnam(sys_get_temp_dir(), 'logist').'.xlsx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }

        parent::tearDown();
    }

    public function test_it_adds_address_to_the_client_matched_by_tax_id(): void
    {
        $user = User::factory()->create(['name' => 'Ромашка']);
        Company::factory()->create(['user_id' => $user->id, 'tax_id' => '7712345678', 'name' => 'Ромашка ООО']);

        $this->fakeDaData();
        $this->makeSheet([
            ['29УТ-000190', 'Ромашка ООО, г.Москва', 'доставка', 'Москва, ул Иркутская, д. 11, к. 1, офис 102', "ИНН 7712345678\nтел +7 915 000-00-00"],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])
            ->assertSuccessful();

        $address = DeliveryAddress::withoutGlobalScopes()->where('user_id', $user->id)->sole();

        // Офис геокодер не вернул — импорт дописывает его к разобранному адресу сам.
        $this->assertSame('127410, г Москва, ул Иркутская, д 11 к 1, офис 102', $address->address);
        $this->assertSame('г Москва, ул Иркутская, д 11', $address->name);
        $this->assertSame('52840d4c', $address->address_data['fias_id']);
        $this->assertTrue($address->is_default);
    }

    public function test_it_matches_client_by_name_when_tax_id_is_missing(): void
    {
        $user = User::factory()->create(['name' => 'Петров Пётр Петрович', 'erp_name' => 'Петров Пётр Петрович']);

        $this->fakeDaData();
        $this->makeSheet([
            ['29УТ-000191', 'Петров Пётр Петрович ИП, г.Москва', 'доставка', 'Москва, ул Иркутская, д. 11, к. 1, офис 102', 'тел +7 915 000-00-00'],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(1, DeliveryAddress::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_it_does_not_touch_clients_it_cannot_identify(): void
    {
        User::factory()->create(['name' => 'Ромашка']);

        $this->fakeDaData();
        $this->makeSheet([
            ['29УТ-000192', 'Интернет решения ООО', 'доставка', 'Москва, ул Иркутская, д. 11, к. 1, офис 102', 'ИНН 9999999999'],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, DeliveryAddress::withoutGlobalScopes()->count());
    }

    public function test_it_labels_carrier_terminals_and_never_makes_them_default(): void
    {
        $user = User::factory()->create(['name' => 'Ромашка']);
        Company::factory()->create(['user_id' => $user->id, 'tax_id' => '7712345678', 'name' => 'Ромашка ООО']);

        $this->fakeDaData(value: 'г Самара', unrestricted: '443010, Самарская обл, г Самара', data: [
            'fias_id' => 'samara-fias',
            'city' => 'Самара',
            'city_with_type' => 'г Самара',
            'house' => null,
        ]);
        $this->makeSheet([
            ['29УТ-000193', 'Ромашка ООО', 'ТК Деловые линии', 'терминал в г.Самара', 'ИНН 7712345678'],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])
            ->assertSuccessful();

        $address = DeliveryAddress::withoutGlobalScopes()->where('user_id', $user->id)->sole();

        $this->assertSame('Терминал Деловые линии, Самара', $address->name);
        // Дом DaData не разобрала — значит сохраняем исходную строку, а не «г Самара».
        $this->assertSame('терминал в г.Самара', $address->address);
        $this->assertFalse($address->is_default);
    }

    public function test_it_skips_self_pickup_and_empty_addresses(): void
    {
        $user = User::factory()->create(['name' => 'Ромашка']);
        Company::factory()->create(['user_id' => $user->id, 'tax_id' => '7712345678', 'name' => 'Ромашка ООО']);

        $this->fakeDaData();
        $this->makeSheet([
            ['29УТ-000194', 'Ромашка ООО', 'самовывоз', 'Москва, ул Иркутская, д. 11', 'ИНН 7712345678'],
            ['29УТ-000195', 'Ромашка ООО', 'доставка', '', 'ИНН 7712345678'],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, DeliveryAddress::withoutGlobalScopes()->count());
    }

    public function test_repeated_run_does_not_duplicate_addresses(): void
    {
        $user = User::factory()->create(['name' => 'Ромашка']);
        Company::factory()->create(['user_id' => $user->id, 'tax_id' => '7712345678', 'name' => 'Ромашка ООО']);

        $this->fakeDaData();
        // Одна и та же точка, записанная в таблице по-разному, — это один адрес.
        $this->makeSheet([
            ['29УТ-000196', 'Ромашка ООО', 'доставка', 'Москва, ул Иркутская, д. 11, к. 1, офис 102', 'ИНН 7712345678'],
            ['29УТ-000197', 'Ромашка ООО', 'доставка', 'москва ул. Иркутская д 11 к.1 офис 102', 'ИНН 7712345678'],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])->assertSuccessful();
        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])->assertSuccessful();

        $this->assertSame(1, DeliveryAddress::withoutGlobalScopes()->where('user_id', $user->id)->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $user = User::factory()->create(['name' => 'Ромашка']);
        Company::factory()->create(['user_id' => $user->id, 'tax_id' => '7712345678', 'name' => 'Ромашка ООО']);

        $this->fakeDaData();
        $this->makeSheet([
            ['29УТ-000198', 'Ромашка ООО', 'доставка', 'Москва, ул Иркутская, д. 11, к. 1, офис 102', 'ИНН 7712345678'],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, DeliveryAddress::withoutGlobalScopes()->count());
    }

    public function test_it_keeps_the_address_the_client_already_saved(): void
    {
        $user = User::factory()->create(['name' => 'Ромашка']);
        Company::factory()->create(['user_id' => $user->id, 'tax_id' => '7712345678', 'name' => 'Ромашка ООО']);

        DeliveryAddress::withoutGlobalScopes()->create([
            'user_id' => $user->id,
            'name' => 'Мой склад',
            'address' => '127410, г Москва, ул Иркутская, д 11 к 1',
            'address_data' => ['fias_id' => '52840d4c', 'flat' => null],
            'is_default' => true,
        ]);

        $this->fakeDaData();
        $this->makeSheet([
            ['29УТ-000199', 'Ромашка ООО', 'доставка', 'Москва, ул Иркутская, д. 11, к. 1', 'ИНН 7712345678'],
        ]);

        $this->artisan('logistics:import-addresses', ['file' => $this->file, '--sheets' => '2026 год', '--force' => true])
            ->assertSuccessful();

        $address = DeliveryAddress::withoutGlobalScopes()->where('user_id', $user->id)->sole();
        $this->assertSame('Мой склад', $address->name);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $rows  заказ, контрагент, тип доставки, адрес, получатель
     */
    private function makeSheet(array $rows): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('2026 год');

        // Шапка листа 2026 года: подпись первой колонки в таблице затёрта.
        $sheet->fromArray([['', '3.0', 'Количество мест', 'Тип доставки', 'Адрес', 'Получатель (ИНН, ФИО, телефон)', 'Менеджер']], null, 'A1');

        $line = 2;
        // Строка-разделитель с датой — импорт обязан её пропустить.
        $sheet->setCellValue('A'.$line++, "20.01.2026\nВторник");

        foreach ($rows as $row) {
            [$order, $client, $delivery, $address, $recipient] = $row;
            $sheet->fromArray([[$order, $client, '', $delivery, $address, $recipient, 'Москва: Курочкина Алёна Валерьевна']], null, 'A'.$line++);
        }

        (new Xlsx($book))->save($this->file);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function fakeDaData(
        string $value = 'г Москва, ул Иркутская, д 11 к 1',
        string $unrestricted = '127410, г Москва, ул Иркутская, д 11 к 1',
        ?array $data = null,
    ): void {
        Http::fake([
            '*/suggest/address' => Http::response([
                'suggestions' => [[
                    'value' => $value,
                    'unrestricted_value' => $unrestricted,
                    'data' => $data ?? [
                        'fias_id' => '52840d4c',
                        'city' => 'Москва',
                        'city_with_type' => 'г Москва',
                        'street_with_type' => 'ул Иркутская',
                        'house_type' => 'д',
                        'house' => '11',
                        'flat' => null,
                    ],
                ]],
            ]),
        ]);
    }
}
