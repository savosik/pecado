<?php

namespace App\Services\Delivery;

use App\Models\DeliveryAddress;
use App\Models\User;

/**
 * Адресная книга доставки клиента — одна реализация для кабинета, чекаута и API v1.
 *
 * Раньше «сохранить адрес из чекаута» и «добавить адрес в списке» жили в двух
 * контроллерах и уже расходились в правилах дублей и умолчания.
 */
class DeliveryAddressBook
{
    /**
     * Запомнить адрес, введённый при оформлении: дубли (точное совпадение строки)
     * не создаются, при make_default адрес становится основным.
     *
     * @param  array<string, mixed>|null  $addressData
     */
    public function remember(User $user, string $address, ?string $name = null, ?array $addressData = null, bool $makeDefault = false): ?DeliveryAddress
    {
        $address = trim($address);

        if ($address === '') {
            return null;
        }

        $existing = $user->deliveryAddresses()->where('address', $address)->first();

        if ($existing) {
            if ($makeDefault && ! $existing->is_default) {
                $this->setDefault($user, $existing);
            }

            return $existing;
        }

        if ($makeDefault) {
            DeliveryAddress::where('user_id', $user->id)->update(['is_default' => false]);
        }

        $name = trim((string) $name);

        return $user->deliveryAddresses()->create([
            'name' => $name !== '' ? $name : 'Адрес доставки',
            'address' => $address,
            'address_data' => $addressData,
            'is_default' => $makeDefault,
        ]);
    }

    public function setDefault(User $user, DeliveryAddress $address): void
    {
        DeliveryAddress::where('user_id', $user->id)->update(['is_default' => false]);
        $address->update(['is_default' => true]);
    }
}
