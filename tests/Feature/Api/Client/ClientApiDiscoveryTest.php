<?php

namespace Tests\Feature\Api\Client;

use App\Models\Company;
use App\Services\Client\Api\FeatureGate;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationRegistry;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

class ClientApiDiscoveryTest extends ClientApiTestCase
{
    #[Test]
    #[TestDox('/me отдаёт актора, юрлица, состояние разделов и каталог операций из реестра')]
    public function me_describes_the_actor_and_the_catalog(): void
    {
        Company::factory()->create(['user_id' => $this->client->id, 'tax_id' => '7727563778', 'is_default' => false]);
        config(['documents.enabled' => false, 'order_reserve.enabled' => false]);

        $response = $this->api('GET', '/me')->assertOk();

        $response->assertJsonPath('data.actor.id', $this->client->id)
            ->assertJsonPath('data.companies.0.id', $this->company->id)
            ->assertJsonPath('data.companies.0.is_default', true)
            ->assertJsonCount(2, 'data.companies')
            ->assertJsonPath('data.features.documents', false)
            ->assertJsonPath('data.features.reserve', false)
            ->assertJsonPath('data.docs.mcp', url('/mcp/client'));

        $operations = collect($response->json('data.operations'));
        $this->assertSame(
            count(app(OperationRegistry::class)->all()),
            $operations->count(),
            'Каталог /me обязан совпадать с реестром — иначе агент строит вызов по устаревшему списку.'
        );

        $profile = $operations->firstWhere('id', 'profile.get');
        $this->assertTrue($profile['allowed']);
        $this->assertSame('/api/client/v1/profile', $profile['path']);
        $this->assertArrayHasKey('schema', $profile);
    }

    #[Test]
    #[TestDox('Каждая операция реестра получила маршрут с ограничениями пути, и наоборот')]
    public function every_callable_operation_has_a_route(): void
    {
        $routes = app('router')->getRoutes();
        $registry = app(OperationRegistry::class);
        $ids = [];

        foreach ($registry->callable() as $operation) {
            $route = $routes->getByName('api.client.v1.'.$operation->id);
            $this->assertNotNull($route, "Операция «{$operation->id}» без маршрута.");
            $this->assertSame([$operation->method], array_values(array_diff($route->methods(), ['HEAD'])));
            $this->assertSame('api/client/v1/'.$operation->uri, $route->uri());

            foreach ($operation->routeConstraints() as $param => $pattern) {
                $this->assertSame($pattern, $route->wheres[$param] ?? null, "Параметр {$param} операции {$operation->id} без ограничения.");
            }

            $ids[] = $operation->id;
        }

        foreach ($routes as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, 'api.client.v1.') && $name !== 'api.client.v1.me' && ! str_starts_with($name, 'api.client.v1.files.')) {
                $this->assertContains(substr($name, strlen('api.client.v1.')), $ids, "Маршрут {$name} без операции в реестре.");
            }
        }
    }

    #[Test]
    #[TestDox('Идентификаторы операций уникальны, секции описаны')]
    public function registry_is_consistent(): void
    {
        $registry = app(OperationRegistry::class);
        $ids = array_map(fn (Operation $o) => $o->id, $registry->all());

        $this->assertSame($ids, array_values(array_unique($ids)));

        foreach ($registry->all() as $operation) {
            $this->assertArrayHasKey($operation->section, $registry->sections(), "Секция «{$operation->section}» операции {$operation->id} не описана.");
            $this->assertNotSame('', $operation->summary);
            $this->assertNotSame('', $operation->description);

            if ($operation->method === 'DELETE') {
                $this->assertSame('carts.delete', $operation->id, 'Удаление через API есть только у корзин.');
            }

            if ($operation->idempotencyRequired) {
                $this->assertTrue($operation->idempotent);
            }
        }
    }

    #[Test]
    #[TestDox('Операция выполняется по маршруту и возвращает конверт data')]
    public function an_operation_runs_through_its_route(): void
    {
        $this->api('GET', '/profile')
            ->assertOk()
            ->assertJsonPath('data.id', $this->client->id)
            ->assertJsonPath('data.preorders_enabled', true)
            ->assertJsonPath('data.reserve_available', false);
    }

    #[Test]
    #[TestDox('Ошибки валидации приходят в конверте errors с полем и русским текстом')]
    public function validation_errors_use_the_envelope(): void
    {
        $this->api('PATCH', '/profile', ['email' => 'не-почта'])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'validation')
            ->assertJsonPath('errors.0.field', 'email');
    }

    #[Test]
    #[TestDox('Пишущая операция попадает в аудит client-agent, чтение — нет')]
    public function mutating_operations_are_audited(): void
    {
        $channel = Log::spy();
        Log::shouldReceive('channel')->with('client-agent')->andReturn($channel);

        $this->api('GET', '/profile')->assertOk();
        $channel->shouldNotHaveReceived('info');

        $this->api('PATCH', '/profile', ['phone' => '+7 900 000-00-00'])->assertOk()
            ->assertJsonPath('data.phone', '+7 900 000-00-00');

        $channel->shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context): bool => $message === 'profile.update'
                && $context['token'] === 'Агент клиента'
                && $context['token_id'] === $this->token->id
                && $context['user_id'] === $this->client->id)
            ->once();
    }

    #[Test]
    #[TestDox('Выключенный раздел отвечает 403 с кодом гейта, а не 404')]
    public function a_closed_feature_gate_gives_403_with_code(): void
    {
        config(['documents.enabled' => false]);

        $this->assertFalse(FeatureGate::DOCUMENTS->allows($this->client));
        $this->assertSame('documents_disabled', FeatureGate::DOCUMENTS->code());

        // Гейт кабинета — тот же предикат: закрытый раздел даёт 404 в вебе.
        $this->actingAs($this->client)->get('/cabinet/documents')->assertStatus(404);

        config(['documents.enabled' => true]);
        $this->assertTrue(FeatureGate::DOCUMENTS->allows($this->client));
    }
}
