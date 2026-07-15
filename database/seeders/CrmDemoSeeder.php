<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Демо-данные CRM: РОП, два менеджера с карточками и клиенты для наполнения списков.
 *
 * WithoutModelEvents обязателен: User::$dispatchesEvents шлёт UserCreated/UserUpdated,
 * их слушает PublishUserToErp — без трейта демо-клиенты уехали бы в 1С как контрагенты.
 *
 * Запускается только вручную:
 *   php artisan db:seed --class=Database\Seeders\CrmDemoSeeder
 */
class CrmDemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Сотрудники CRM: аккаунт + карточка персонального менеджера.
     *
     * Имена вымышленные и намеренно не совпадают с реальными менеджерами прода —
     * иначе демо-двойник путался бы с карточкой из 1С в списке менеджеров.
     */
    protected array $staff = [
        [
            'email' => 'rop@pecado.ru',
            'password' => 'Rop2024!',
            'account_name' => 'Медведев Сергей (РОП)',
            'role' => 'sales-head',
            'manager_name' => 'Медведев Сергей',
            'phone' => '+7 (900) 000-10-01',
        ],
        [
            'email' => 'manager1@pecado.ru',
            'password' => 'Manager2024!',
            'account_name' => 'Волков Пётр',
            'role' => 'sales-manager',
            'manager_name' => 'Волков Пётр',
            'phone' => '+7 (900) 000-10-02',
        ],
        [
            'email' => 'manager2@pecado.ru',
            'password' => 'Manager2024!',
            'account_name' => 'Зайцева Мария',
            'role' => 'sales-manager',
            'manager_name' => 'Зайцева Мария',
            'phone' => '+7 (900) 000-10-03',
        ],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->warn('CrmDemoSeeder пропущен: демо-данные не создаются в production.');

            return;
        }

        $managerIds = [];

        foreach ($this->staff as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['account_name'],
                    'password' => $data['password'],
                    'status' => UserStatus::ACTIVE,
                ]
            );
            $user->syncRoles([$data['role']]);

            // Ключ идемпотентности — user_id (он unique). erp_uuid оставляем null:
            // фиктивный UUID выдал бы демо-карточку за пришедшую из 1С.
            $manager = PersonalManager::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'name' => $data['manager_name'],
                    'phone' => $data['phone'],
                    'email' => $data['email'],
                ]
            );

            if ($data['role'] === 'sales-manager') {
                $managerIds[] = $manager->id;
            }

            $this->command->info("Сотрудник «{$data['manager_name']}» ({$data['email']}) — роль {$data['role']}, карточка #{$manager->id}.");
        }

        $this->seedClients($managerIds);
    }

    /**
     * Демо-клиенты в отдельном почтовом пространстве @demo.pecado.ru:
     * не пересекаются с реальными пользователями и находятся одним LIKE.
     *
     * @param  array<int, int>  $managerIds
     */
    private function seedClients(array $managerIds): void
    {
        if ($managerIds === []) {
            return;
        }

        foreach (range(1, 12) as $i) {
            User::updateOrCreate(
                ['email' => "crm-client-{$i}@demo.pecado.ru"],
                [
                    'name' => "Демо-клиент {$i}",
                    'password' => Str::random(32),
                    'status' => UserStatus::ACTIVE,
                    'personal_manager_id' => $managerIds[$i % count($managerIds)],
                ]
            );
        }

        $this->command->info('Создано 12 демо-клиентов, поровну распределены между менеджерами.');
    }
}
