<?php

namespace Tests\Feature\DaData;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DaDataControllerTest extends TestCase
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

    public function test_неавторизованный_пользователь_не_может_дёргать_прокси(): void
    {
        $response = $this->postJson('/api/dadata/suggest/party', ['query' => 'Сбер']);

        $response->assertStatus(401);
    }

    public function test_suggest_party_возвращает_подсказки_dadata(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'ПАО Сбербанк', 'data' => ['inn' => '7707083893']],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/suggest/party', ['query' => 'Сбер', 'count' => 5]);

        $response->assertStatus(200);
        $response->assertJsonPath('suggestions.0.data.inn', '7707083893');
    }

    public function test_find_party_by_inn_возвращает_party_и_кэширует_повторный_запрос(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'ПАО Сбербанк', 'data' => ['inn' => '7707083893', 'kpp' => '773601001']],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $first = $this->actingAs($user)
            ->postJson('/api/dadata/findById/party', ['inn' => '7707083893']);
        $second = $this->actingAs($user)
            ->postJson('/api/dadata/findById/party', ['inn' => '7707083893']);

        $first->assertStatus(200)->assertJsonPath('party.data.kpp', '773601001');
        $second->assertStatus(200)->assertJsonPath('party.data.kpp', '773601001');

        Http::assertSentCount(1);
    }

    public function test_find_party_by_inn_возвращает_null_party_когда_dadata_ничего_не_нашёл(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response(['suggestions' => []], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/findById/party', ['inn' => '7707083893']);

        $response->assertStatus(200);
        $response->assertJsonPath('party', null);
    }

    public function test_валидация_отклоняет_короткий_query(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/suggest/party', ['query' => 'a']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('query');
    }

    public function test_валидация_отклоняет_невалидный_формат_инн(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/findById/party', ['inn' => 'abc']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('inn');
    }

    public function test_сервис_возвращает_503_при_сбое_dadata(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response('boom', 500),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/suggest/party', ['query' => 'Сбер']);

        $response->assertStatus(503);
    }

    public function test_suggest_bank_возвращает_подсказки_dadata(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'ПАО СБЕРБАНК', 'data' => ['bic' => '044525225']],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/suggest/bank', ['query' => 'Сбер']);

        $response->assertStatus(200);
        $response->assertJsonPath('suggestions.0.data.bic', '044525225');
    }

    public function test_find_bank_by_bik_возвращает_bank_и_кэширует(): void
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

        $user = User::factory()->create();

        $first = $this->actingAs($user)
            ->postJson('/api/dadata/findById/bank', ['bik' => '044525225']);
        $second = $this->actingAs($user)
            ->postJson('/api/dadata/findById/bank', ['bik' => '044525225']);

        $first->assertStatus(200)->assertJsonPath('bank.data.correspondent_account', '30101810400000000225');
        $second->assertStatus(200)->assertJsonPath('bank.data.correspondent_account', '30101810400000000225');

        Http::assertSentCount(1);
    }

    public function test_валидация_отклоняет_невалидный_бик(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/findById/bank', ['bik' => 'abc']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('bik');
    }

    public function test_find_bank_by_bik_возвращает_null_когда_dadata_ничего_не_нашёл(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response(['suggestions' => []], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/findById/bank', ['bik' => '999999999']);

        $response->assertStatus(200);
        $response->assertJsonPath('bank', null);
    }

    public function test_suggest_address_возвращает_подсказки(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'г Москва, ул Тверская, д 7', 'data' => ['fias_id' => 'abc', 'postal_code' => '125009']],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/suggest/address', ['query' => 'Москва Тверская 7']);

        $response->assertStatus(200);
        $response->assertJsonPath('suggestions.0.data.postal_code', '125009');
    }

    public function test_suggest_address_отклоняет_короткий_query(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/suggest/address', ['query' => 'м']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('query');
    }

    public function test_geolocate_address_возвращает_подсказки_по_координатам(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'г Москва, ул Тверская, д 7', 'data' => ['postal_code' => '125009']],
                ],
            ], 200),
        ]);

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/geolocate/address', ['lat' => 55.7558, 'lon' => 37.6173]);

        $response->assertStatus(200);
        $response->assertJsonPath('suggestions.0.data.postal_code', '125009');
    }

    public function test_geolocate_address_отклоняет_невалидные_координаты(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/dadata/geolocate/address', ['lat' => 200, 'lon' => 0]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('lat');
    }

    public function test_suggest_email_доступен_без_авторизации(): void
    {
        Http::fake([
            'suggestions.dadata.ru/*' => Http::response([
                'suggestions' => [
                    ['value' => 'vasya@gmail.com'],
                    ['value' => 'vasya@mail.ru'],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/dadata/suggest/email', ['query' => 'vasya@']);

        $response->assertStatus(200);
        $response->assertJsonPath('suggestions.0.value', 'vasya@gmail.com');
    }

    public function test_suggest_email_валидация_отклоняет_пустой_query(): void
    {
        $response = $this->postJson('/api/dadata/suggest/email', ['query' => '']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('query');
    }
}
