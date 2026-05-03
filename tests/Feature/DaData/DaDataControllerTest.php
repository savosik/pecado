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
}
