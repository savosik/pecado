<?php

namespace Tests\Feature\Api\Client;

use App\Models\ClientApiIdempotencyKey;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Idempotency\IdempotencyConflict;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationRunner;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;

/**
 * Обработчик-зонд: считает вызовы и умеет падать по команде.
 */
class IdempotencyProbe
{
    public static int $calls = 0;

    public static bool $fail = false;

    /**
     * @return array<string, mixed>
     */
    public function run(User $actor, OperationInput $input): array
    {
        self::$calls++;

        if (self::$fail) {
            throw new RuntimeException('Обработчик упал.');
        }

        return Envelope::data(['call' => self::$calls, 'value' => $input->string('value')], ['created' => true]);
    }
}

class ClientApiIdempotencyTest extends ClientApiTestCase
{
    private OperationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        IdempotencyProbe::$calls = 0;
        IdempotencyProbe::$fail = false;
        $this->runner = app(OperationRunner::class);
    }

    private function operation(bool $required = true): Operation
    {
        return new Operation(
            id: 'probe.create',
            section: 'profile',
            method: 'POST',
            uri: 'probe',
            summary: 'Зонд',
            description: 'Зонд идемпотентности',
            params: [Param::string('value', 'Значение', true)],
            handler: [IdempotencyProbe::class, 'run'],
            mutating: true,
            idempotent: true,
            idempotencyRequired: $required,
        );
    }

    #[Test]
    #[TestDox('Повтор с тем же ключом и телом возвращает сохранённый ответ, обработчик не вызывается')]
    public function a_repeat_replays_the_stored_response(): void
    {
        $first = $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-1');
        $second = $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-1');

        $this->assertSame(1, IdempotencyProbe::$calls);
        $this->assertSame($first['data'], $second['data']);
        $this->assertTrue($second['meta']['idempotent_replay']);
        $this->assertArrayNotHasKey('idempotent_replay', $first['meta']);

        $this->assertDatabaseHas('client_api_idempotency_keys', [
            'user_id' => $this->client->id,
            'operation' => 'probe.create',
            'key' => 'k-1',
            'status' => ClientApiIdempotencyKey::STATUS_DONE,
        ]);
    }

    #[Test]
    #[TestDox('Тот же ключ с другим телом отклоняется — ключ является отпечатком запроса')]
    public function the_same_key_with_another_body_is_rejected(): void
    {
        $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-2');

        try {
            $this->runner->run($this->operation(), $this->client, ['value' => 'b'], 'k-2');
            $this->fail('Ожидался IdempotencyConflict');
        } catch (IdempotencyConflict $e) {
            $this->assertSame('idempotency_key_reused', $e->errorCode);
            $this->assertSame(422, $e->status);
        }

        $this->assertSame(1, IdempotencyProbe::$calls);
    }

    #[Test]
    #[TestDox('Порядок ключей в теле не меняет отпечаток')]
    public function key_order_does_not_change_the_hash(): void
    {
        $op = new Operation(
            id: 'probe.create', section: 'profile', method: 'POST', uri: 'probe', summary: 'Зонд', description: 'Зонд',
            params: [Param::string('value', 'Значение', true), Param::string('other', 'Другое')],
            handler: [IdempotencyProbe::class, 'run'], mutating: true, idempotent: true,
        );

        $this->runner->run($op, $this->client, ['value' => 'a', 'other' => 'x'], 'k-3');
        $replay = $this->runner->run($op, $this->client, ['other' => 'x', 'value' => 'a'], 'k-3');

        $this->assertSame(1, IdempotencyProbe::$calls);
        $this->assertTrue($replay['meta']['idempotent_replay']);
    }

    #[Test]
    #[TestDox('Пока запрос выполняется, параллельный повтор получает 409')]
    public function a_parallel_repeat_is_told_to_wait(): void
    {
        ClientApiIdempotencyKey::query()->create([
            'user_id' => $this->client->id,
            'operation' => 'probe.create',
            'key' => 'k-4',
            'request_hash' => app(\App\Services\Client\Api\Idempotency\IdempotencyStore::class)->hash(['value' => 'a']),
            'status' => ClientApiIdempotencyKey::STATUS_IN_PROGRESS,
            'created_at' => now(),
            'expires_at' => now()->addDay(),
        ]);

        try {
            $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-4');
            $this->fail('Ожидался IdempotencyConflict');
        } catch (IdempotencyConflict $e) {
            $this->assertSame('idempotency_in_progress', $e->errorCode);
            $this->assertSame(409, $e->status);
        }

        $this->assertSame(0, IdempotencyProbe::$calls);
    }

    #[Test]
    #[TestDox('Провал обработчика освобождает ключ — повтор выполняется')]
    public function a_failure_frees_the_key(): void
    {
        IdempotencyProbe::$fail = true;

        try {
            $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-5');
            $this->fail('Ожидался RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('Обработчик упал.', $e->getMessage());
        }

        $this->assertDatabaseMissing('client_api_idempotency_keys', ['key' => 'k-5']);

        IdempotencyProbe::$fail = false;
        $result = $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-5');

        $this->assertSame(2, IdempotencyProbe::$calls);
        $this->assertSame('a', $result['data']['value']);
    }

    #[Test]
    #[TestDox('Обязательная операция без ключа не выполняется; необязательная — выполняется без записи')]
    public function the_key_is_required_only_where_declared(): void
    {
        try {
            $this->runner->run($this->operation(true), $this->client, ['value' => 'a']);
            $this->fail('Ожидался IdempotencyConflict');
        } catch (IdempotencyConflict $e) {
            $this->assertSame('idempotency_key_required', $e->errorCode);
        }

        $this->assertSame(0, IdempotencyProbe::$calls);

        $this->runner->run($this->operation(false), $this->client, ['value' => 'a']);
        $this->runner->run($this->operation(false), $this->client, ['value' => 'a']);

        $this->assertSame(2, IdempotencyProbe::$calls);
        $this->assertDatabaseCount('client_api_idempotency_keys', 0);
    }

    #[Test]
    #[TestDox('Ключи разных клиентов не пересекаются')]
    public function keys_are_scoped_to_the_client(): void
    {
        $other = User::factory()->create();

        $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'shared');
        $this->runner->run($this->operation(), $other, ['value' => 'b'], 'shared');

        $this->assertSame(2, IdempotencyProbe::$calls);
    }

    #[Test]
    #[TestDox('Просроченные ключи удаляются model:prune, а протухший ключ занимается заново')]
    public function expired_keys_are_pruned_and_reusable(): void
    {
        $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-6');

        $this->travel(25)->hours();

        $result = $this->runner->run($this->operation(), $this->client, ['value' => 'a'], 'k-6');
        $this->assertSame(2, IdempotencyProbe::$calls);
        $this->assertArrayNotHasKey('idempotent_replay', $result['meta']);

        $this->travel(25)->hours();
        Artisan::call('model:prune', ['--model' => [ClientApiIdempotencyKey::class]]);

        $this->assertDatabaseCount('client_api_idempotency_keys', 0);
    }
}
