<?php

namespace Tests\Feature\Api\Client;

use App\Models\ApiToken;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiAuthTest extends ClientApiTestCase
{
    #[Test]
    #[TestDox('Без токена, с чужим и с отозванным — 401 с одинаковым текстом')]
    public function the_gate_requires_an_active_token(): void
    {
        $expected = ['errors' => [['code' => 'unauthorized', 'message' => 'Токен недействителен или отозван.']]];

        $this->getJson('/api/client/v1/me')->assertStatus(401)->assertExactJson($expected);
        $this->api('GET', '/me', token: 'nope')->assertStatus(401)->assertExactJson($expected);

        $this->token->update(['is_active' => false]);
        $this->api('GET', '/me')->assertStatus(401)->assertExactJson($expected);
    }

    #[Test]
    #[TestDox('Токен без владельца не пускает')]
    public function a_token_without_owner_is_rejected(): void
    {
        $orphan = User::factory()->create();
        $token = ApiToken::create(['user_id' => $orphan->id, 'name' => 'Сирота', 'is_active' => true]);
        $orphan->forceDelete();

        $this->api('GET', '/me', token: $token->token)->assertStatus(401);
    }

    #[Test]
    #[TestDox('Отметка last_used_at ставится не чаще раза в минуту')]
    public function last_used_is_touched_at_most_once_a_minute(): void
    {
        $this->assertNull($this->token->last_used_at);

        $this->api('GET', '/me')->assertOk();
        $first = $this->token->refresh()->last_used_at;
        $this->assertNotNull($first);

        $this->travel(10)->seconds();
        $this->api('GET', '/me')->assertOk();
        $this->assertTrue($this->token->refresh()->last_used_at->equalTo($first));

        $this->travel(2)->minutes();
        $this->api('GET', '/me')->assertOk();
        $this->assertTrue($this->token->refresh()->last_used_at->gt($first));
    }

    #[Test]
    #[TestDox('Legacy-токен в пути не работает как Bearer-адрес, а Bearer не пускает в legacy без пути')]
    public function transports_do_not_leak_into_each_other(): void
    {
        $this->getJson('/api/client/v1/'.$this->token->token.'/me')->assertStatus(404);
        $this->api('GET', '/me')->assertOk();
    }
}
