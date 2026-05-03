<?php

namespace Tests\Unit\DaData;

use App\Services\DaData\DaDataClient;
use App\Services\DaData\DaDataException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DaDataClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.dadata.api_key' => 'test-api-key',
            'services.dadata.secret_key' => 'test-secret-key',
            'services.dadata.suggestions_url' => 'https://suggestions.dadata.ru/suggestions/api/4_1/rs',
            'services.dadata.cache_ttl' => 60,
            'services.dadata.request_timeout' => 5,
        ]);

        Cache::flush();
    }

    public function test_suggest_party_отправляет_корректный_запрос_и_возвращает_подсказки(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'ПАО Сбербанк', 'data' => ['inn' => '7707083893']],
                ],
            ], 200),
        ]);

        $suggestions = (new DaDataClient)->suggestParty('Сбер', 5);

        $this->assertCount(1, $suggestions);
        $this->assertSame('7707083893', $suggestions[0]['data']['inn']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/party'
                && $request['query'] === 'Сбер'
                && $request['count'] === 5
                && $request->hasHeader('Authorization', 'Token test-api-key')
                && $request->hasHeader('X-Secret', 'test-secret-key');
        });
    }

    public function test_find_party_by_inn_возвращает_первую_подсказку(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'ПАО Сбербанк', 'data' => ['inn' => '7707083893', 'ogrn' => '1027700132195']],
                ],
            ], 200),
        ]);

        $party = (new DaDataClient)->findPartyByInn('7707083893');

        $this->assertNotNull($party);
        $this->assertSame('1027700132195', $party['data']['ogrn']);
    }

    public function test_find_party_by_inn_возвращает_null_при_пустом_ответе(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response(['suggestions' => []], 200),
        ]);

        $party = (new DaDataClient)->findPartyByInn('1234567890');

        $this->assertNull($party);
    }

    public function test_find_party_by_inn_кэширует_ответ_и_не_дёргает_dadata_повторно(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [['value' => 'ПАО Сбербанк', 'data' => ['inn' => '7707083893']]],
            ], 200),
        ]);

        $client = new DaDataClient;
        $client->findPartyByInn('7707083893');
        $client->findPartyByInn('7707083893');

        Http::assertSentCount(1);
    }

    public function test_бросает_исключение_при_отсутствии_api_ключа(): void
    {
        config(['services.dadata.api_key' => '']);

        $this->expectException(DaDataException::class);

        (new DaDataClient)->suggestParty('Сбер');
    }

    public function test_бросает_исключение_при_5xx_от_dadata(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response('Internal Server Error', 500),
        ]);

        $this->expectException(DaDataException::class);

        (new DaDataClient)->suggestParty('Сбер');
    }

    public function test_suggest_bank_отправляет_запрос_и_возвращает_подсказки(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'ПАО СБЕРБАНК', 'data' => ['bic' => '044525225']],
                ],
            ], 200),
        ]);

        $suggestions = (new DaDataClient)->suggestBank('Сбер', 5);

        $this->assertCount(1, $suggestions);
        $this->assertSame('044525225', $suggestions[0]['data']['bic']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/bank'
                && $request['query'] === 'Сбер'
                && $request['count'] === 5;
        });
    }

    public function test_find_bank_by_bik_возвращает_первую_подсказку(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [[
                    'value' => 'ПАО СБЕРБАНК',
                    'data' => [
                        'bic' => '044525225',
                        'correspondent_account' => '30101810400000000225',
                    ],
                ]],
            ], 200),
        ]);

        $bank = (new DaDataClient)->findBankByBik('044525225');

        $this->assertNotNull($bank);
        $this->assertSame('30101810400000000225', $bank['data']['correspondent_account']);
    }

    public function test_find_bank_by_bik_кэширует_ответ(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [['value' => 'ПАО СБЕРБАНК', 'data' => ['bic' => '044525225']]],
            ], 200),
        ]);

        $client = new DaDataClient;
        $client->findBankByBik('044525225');
        $client->findBankByBik('044525225');

        Http::assertSentCount(1);
    }

    public function test_find_bank_by_bik_возвращает_null_при_пустом_ответе(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response(['suggestions' => []], 200),
        ]);

        $bank = (new DaDataClient)->findBankByBik('999999999');

        $this->assertNull($bank);
    }

    public function test_suggest_address_отправляет_запрос_с_locations(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'г Москва, ул Тверская, д 7', 'data' => ['fias_id' => 'abc']],
                ],
            ], 200),
        ]);

        $suggestions = (new DaDataClient)->suggestAddress('Москва Тверская 7', 5, [
            ['country_iso_code' => 'RU'],
        ]);

        $this->assertCount(1, $suggestions);
        $this->assertSame('abc', $suggestions[0]['data']['fias_id']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address'
                && $request['query'] === 'Москва Тверская 7'
                && $request['count'] === 5
                && $request['locations'] === [['country_iso_code' => 'RU']];
        });
    }

    public function test_suggest_address_без_locations_не_отправляет_ключ(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response(['suggestions' => []], 200),
        ]);

        (new DaDataClient)->suggestAddress('Москва', 3);

        Http::assertSent(function ($request) {
            return ! array_key_exists('locations', $request->data());
        });
    }

    public function test_geolocate_address_отправляет_корректный_запрос(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'г Москва, ул Тверская, д 7', 'data' => ['fias_id' => 'abc']],
                ],
            ], 200),
        ]);

        $suggestions = (new DaDataClient)->geolocateAddress(55.7558, 37.6173, 3, 200);

        $this->assertCount(1, $suggestions);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/geolocate/address'
                && $request['lat'] === 55.7558
                && $request['lon'] === 37.6173
                && $request['count'] === 3
                && $request['radius_meters'] === 200;
        });
    }

    public function test_suggest_email_отправляет_запрос_и_возвращает_подсказки(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'vasya@gmail.com'],
                    ['value' => 'vasya@mail.ru'],
                ],
            ], 200),
        ]);

        $suggestions = (new DaDataClient)->suggestEmail('vasya@', 5);

        $this->assertCount(2, $suggestions);
        $this->assertSame('vasya@gmail.com', $suggestions[0]['value']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/email'
                && $request['query'] === 'vasya@'
                && $request['count'] === 5;
        });
    }
}
