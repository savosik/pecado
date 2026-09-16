<?php

namespace App\Services\Client\Api\Operations;

use App\Models\User;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Crm\ManagerAbsenceResolver;
use App\Services\Order\ReservePolicy;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;
use App\Support\Preorder\PreorderTerms;
use Illuminate\Validation\ValidationException;

/**
 * Профиль клиента: кто я, мой менеджер, мои режимы.
 */
class ProfileOperations implements OperationProvider
{
    public function __construct(
        private readonly ManagerAbsenceResolver $absences,
        private readonly ReservePolicy $reserves,
    ) {}

    public static function section(): array
    {
        return ['profile', 'Профиль'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'profile.get',
                section: 'profile',
                method: 'GET',
                uri: 'profile',
                summary: 'Профиль клиента: контакты, менеджер, режимы предзаказа и резерва',
                description: 'Имя, e-mail и телефон учётной записи, регион и валюта цен, персональный менеджер '
                    .'с учётом замещения (кому звонить), флаг предзаказов и ориентировочный срок поставки, '
                    .'доступность режима «Заказы в резерве». Смена флага предзаказов — решение менеджера, '
                    .'через API он не меняется.',
                params: [],
                handler: [self::class, 'get'],
            ),
            new Operation(
                id: 'profile.update',
                section: 'profile',
                method: 'PATCH',
                uri: 'profile',
                summary: 'Изменить имя, телефон или e-mail учётной записи',
                description: 'Те же поля, что правятся в кабинете. E-mail должен быть уникален среди пользователей.',
                params: [
                    Param::string('name', 'Имя', rules: ['max:255']),
                    Param::string('phone', 'Телефон', rules: ['max:30'], nullable: true),
                    Param::string('email', 'E-mail', rules: ['email', 'max:255']),
                ],
                handler: [self::class, 'update'],
                mutating: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $actor, OperationInput $input): array
    {
        $actor->loadMissing(['region', 'currency', 'personalManager']);

        return Envelope::data([
            'id' => (int) $actor->getKey(),
            'name' => $actor->name,
            'email' => $actor->email,
            'phone' => $actor->phone,
            'region' => $actor->region?->name,
            'currency_code' => $actor->currency?->code,
            'preorders_enabled' => $actor->preordersEnabled(),
            'preorder_lead_days' => PreorderTerms::leadDays(),
            'reserve_available' => $this->reserves->availableFor($actor),
            'manager' => $this->manager($actor),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function update(User $actor, OperationInput $input): array
    {
        $changes = $input->only(['name', 'phone', 'email']);

        if ($changes === []) {
            throw ValidationException::withMessages(['name' => 'Передайте хотя бы одно поле: name, phone или email.']);
        }

        if (isset($changes['email'])) {
            $taken = User::query()
                ->where('email', $changes['email'])
                ->whereKeyNot($actor->getKey())
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['email' => 'Этот email уже используется другим пользователем.']);
            }
        }

        if (array_key_exists('name', $changes) && trim((string) $changes['name']) === '') {
            throw ValidationException::withMessages(['name' => 'Имя обязательно для заполнения.']);
        }

        $actor->update($changes);

        return $this->get($actor->refresh(), $input);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manager(User $actor): ?array
    {
        if (! $actor->personalManager) {
            return null;
        }

        $resolution = $this->absences->resolve($actor->personalManager);
        $manager = $resolution->manager;

        return [
            'name' => $manager->name,
            'phone' => $manager->phone,
            'email' => $manager->email,
            'substitution' => $resolution->isSubstitution() ? [
                'absent_manager_name' => $resolution->absentManager->name,
                'until' => $resolution->until->toDateString(),
            ] : null,
        ];
    }
}
