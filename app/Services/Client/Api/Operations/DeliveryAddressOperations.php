<?php

namespace App\Services\Client\Api\Operations;

use App\Models\DeliveryAddress;
use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Delivery\DeliveryAddressBook;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;

/**
 * Адресная книга доставки. Удаления нет — только смена основного.
 */
class DeliveryAddressOperations implements OperationProvider
{
    public function __construct(private readonly DeliveryAddressBook $book) {}

    public static function section(): array
    {
        return ['delivery-addresses', 'Адреса доставки'];
    }

    public static function operations(): array
    {
        $address = Param::integer('address', 'id адреса', true);

        return [
            new Operation(
                id: 'delivery-addresses.list', section: 'delivery-addresses', method: 'GET', uri: 'delivery-addresses',
                summary: 'Адреса доставки',
                description: 'is_default — адрес, предлагаемый в оформлении по умолчанию.',
                params: [],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'delivery-addresses.create', section: 'delivery-addresses', method: 'POST', uri: 'delivery-addresses',
                summary: 'Добавить адрес',
                description: 'Точный дубль строки адреса не создаётся — возвращается существующий. Принимает Idempotency-Key.',
                params: [
                    Param::string('name', 'Название (например, «Склад на Ленина»)', true, ['max:255']),
                    Param::string('address', 'Адрес одной строкой', true, ['max:1000']),
                    Param::boolean('is_default', 'Сделать основным'),
                ],
                handler: [self::class, 'create'],
                mutating: true, idempotent: true,
            ),
            new Operation(
                id: 'delivery-addresses.update', section: 'delivery-addresses', method: 'PATCH', uri: 'delivery-addresses/{address}',
                summary: 'Изменить адрес',
                description: 'Название и/или строка адреса.',
                params: [$address, Param::string('name', 'Название', rules: ['max:255']), Param::string('address', 'Адрес одной строкой', rules: ['max:1000'])],
                handler: [self::class, 'update'],
                mutating: true,
            ),
            new Operation(
                id: 'delivery-addresses.set-default', section: 'delivery-addresses', method: 'POST', uri: 'delivery-addresses/{address}/default',
                summary: 'Сделать адрес основным',
                description: 'Основной адрес — один.',
                params: [$address],
                handler: [self::class, 'setDefault'],
                mutating: true,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        return Envelope::data($actor->deliveryAddresses()->orderByDesc('is_default')->orderByDesc('created_at')->get()
            ->map(fn (DeliveryAddress $a) => $this->row($a))->values()->all());
    }

    /** @return array<string, mixed> */
    public function create(User $actor, OperationInput $input): array
    {
        $address = $this->book->remember($actor, (string) $input->string('address'), $input->string('name'), null, $input->bool('is_default'));

        return Envelope::data($this->row($address), ['created' => $address->wasRecentlyCreated]);
    }

    /** @return array<string, mixed> */
    public function update(User $actor, OperationInput $input): array
    {
        $address = $this->address($actor, $input);
        $address->update(array_filter($input->only(['name', 'address']), fn ($v) => $v !== null && $v !== ''));

        return Envelope::data($this->row($address->refresh()));
    }

    /** @return array<string, mixed> */
    public function setDefault(User $actor, OperationInput $input): array
    {
        $address = $this->address($actor, $input);
        $this->book->setDefault($actor, $address);

        return Envelope::data($this->row($address->refresh()));
    }

    private function address(User $actor, OperationInput $input): DeliveryAddress
    {
        return $actor->deliveryAddresses()->whereKey((int) $input->int('address'))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function row(DeliveryAddress $address): array
    {
        return [
            'id' => (int) $address->id,
            'name' => $address->name,
            'address' => $address->address,
            'is_default' => (bool) $address->is_default,
        ];
    }
}
