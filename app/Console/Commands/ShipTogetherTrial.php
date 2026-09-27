<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\User;
use App\Services\Erp\OrderReservePublisher;
use App\Services\Order\ReserveActionException;
use App\Services\Order\ShipTogetherService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Инструмент совместных испытаний совместной отгрузки (топик №8 Agent Hub, тест-план Р-7).
 *
 * Обычный путь клиента шлёт группу целиком и не умеет ни пропустить сообщение, ни повторить
 * его с тем же message_id. Здесь это делается руками оператора сайта:
 *
 *   send      — отправить группу, при --skip одно сообщение придержать (Р-7.3: тайм-аут, Р-7.4: not_reserved);
 *   resend    — дослать придержанные сообщения группы тем же message_id (до истечения 15 минут);
 *   republish — повторить ВСЕ сообщения группы теми же message_id (Р-7.7 повтор, Р-7.8 поздний ключ);
 *   status    — состояние заказов группы на сайте.
 *
 * Собранные payload хранятся в storage/app/ship-together-trials/{key}.json — только так
 * повтор получает те же message_id, что и первая отправка.
 */
class ShipTogetherTrial extends Command
{
    protected $signature = 'reserve:ship-together-trial
        {action : send | resend | republish | status}
        {--user= : Партнёр: id или erp_id (UUID)}
        {--orders= : Заказы через запятую: id, номер 1С или uuid}
        {--skip= : Заказы, сообщения по которым придержать (через запятую)}
        {--key= : Ключ группы для resend / republish / status}';

    protected $description = 'Испытания совместной отгрузки: отправка группы с пропуском, досылка, повтор, состояние';

    private const DIR = 'ship-together-trials';

    public function handle(ShipTogetherService $service, OrderReservePublisher $publisher): int
    {
        return match ($this->argument('action')) {
            'send' => $this->send($service),
            'resend' => $this->republish($publisher, onlyUnsent: true),
            'republish' => $this->republish($publisher, onlyUnsent: false),
            'status' => $this->status(),
            default => $this->abort('Действие: send | resend | republish | status'),
        };
    }

    private function send(ShipTogetherService $service): int
    {
        $user = $this->resolveUser((string) $this->option('user'));

        if (! $user) {
            return $this->abort('Партнёр не найден: --user=id|erp_id');
        }

        if (! ShipTogetherService::enabledFor($user)) {
            return $this->abort('Совместная отгрузка для этого партнёра выключена (рубильник или канарейка). Испытания идут только при включённом режиме.');
        }

        $orders = $this->resolveOrders($user, (string) $this->option('orders'));
        $skip = $this->resolveOrders($user, (string) $this->option('skip'))->pluck('uuid')->all();

        try {
            $result = $service->confirmGroup($user, $orders->pluck('id')->all(), $skip);
        } catch (ReserveActionException $e) {
            return $this->abort("{$e->errorCode}: {$e->getMessage()}");
        }

        $record = [
            'key' => $result['key'],
            'user_id' => $user->id,
            'partner_uuid' => $user->erp_id,
            'manifest' => $result['orders']->pluck('uuid')->values()->all(),
            'sent_at' => now()->toIso8601String(),
            'messages' => array_map(fn (array $m) => [
                'uuid' => $m['payload']['uuid'],
                'message_id' => $m['payload']['message_id'],
                'sent' => $m['sent'],
                'payload' => $m['payload'],
            ], $result['payloads']),
        ];
        Storage::disk('local')->put(self::DIR."/{$result['key']}.json", json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("ship_together_key={$result['key']}");
        $this->table(['uuid', 'message_id', 'отправлено'], array_map(
            fn (array $m) => [$m['uuid'], $m['message_id'], $m['sent'] ? 'да' : 'ПРИДЕРЖАНО'],
            $record['messages'],
        ));

        return self::SUCCESS;
    }

    private function republish(OrderReservePublisher $publisher, bool $onlyUnsent): int
    {
        $record = $this->loadRecord();

        if (! $record) {
            return self::FAILURE;
        }

        $count = 0;
        foreach ($record['messages'] as &$message) {
            if ($onlyUnsent && $message['sent']) {
                continue;
            }
            $publisher->republish($message['payload']);
            $message['sent'] = true;
            $count++;
            $this->line("→ {$message['uuid']} {$message['message_id']}");
        }
        unset($message);

        Storage::disk('local')->put(self::DIR."/{$record['key']}.json", json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $this->info(($onlyUnsent ? 'Дослано' : 'Повторено').": {$count}");

        return self::SUCCESS;
    }

    private function status(): int
    {
        $key = (string) $this->option('key');

        if ($key === '') {
            return $this->abort('Нужен --key');
        }

        $rows = Order::query()->withTrashed()->where('ship_together_key', $key)->orderBy('id')->get()
            ->map(fn (Order $o) => [
                $o->clientLabel(), $o->uuid, $o->reserve ? 'да' : 'нет',
                $o->ship_together_status?->value ?? '-', $o->ship_together_conflict['reason'] ?? '-',
                $o->reserve_outcome ?? '-', $o->ship_together_sent_at?->format('d.m H:i:s') ?? '-',
            ])->all();

        $this->table(['заказ', 'uuid', 'резерв', 'группа', 'причина', 'исход', 'отправлено'], $rows);

        return self::SUCCESS;
    }

    /** @return array{key: string, messages: list<array{uuid: string, message_id: string, sent: bool, payload: array<string, mixed>}>}|null */
    private function loadRecord(): ?array
    {
        $key = (string) $this->option('key');
        $path = self::DIR."/{$key}.json";

        if ($key === '' || ! Storage::disk('local')->exists($path)) {
            $this->error('Запись группы не найдена: --key=<ship_together_key> из вывода send');

            return null;
        }

        return json_decode((string) Storage::disk('local')->get($path), true);
    }

    private function resolveUser(string $identifier): ?User
    {
        if ($identifier === '') {
            return null;
        }

        return ctype_digit($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('erp_id', $identifier)->first();
    }

    /** @return \Illuminate\Support\Collection<int, Order> */
    private function resolveOrders(User $user, string $list): \Illuminate\Support\Collection
    {
        $identifiers = array_values(array_filter(array_map('trim', explode(',', $list))));

        return collect($identifiers)->map(function (string $id) use ($user): Order {
            $query = Order::query()->where('user_id', $user->id);
            $order = ctype_digit($id)
                ? $query->whereKey((int) $id)->first()
                : $query->where(fn ($q) => $q->where('uuid', $id)->orWhere('erp_number', $id))->first();

            if (! $order) {
                throw new \RuntimeException("Заказ не найден у партнёра: {$id}");
            }

            return $order;
        });
    }

    private function abort(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
