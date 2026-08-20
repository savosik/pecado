<?php

namespace App\Services\Notifications;

use App\Enums\ClientContactRole;
use App\Models\ClientContact;
use App\Models\CrmClientProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Адресная книга партнёра: выборка, подсказки и импорт черновиков.
 *
 * Заполненность адресной книги — главный риск пульта уведомлений: правило
 * «бухгалтеру этого контрагента» молчит, если бухгалтеров не завели, и выясняется
 * это через жалобу клиента. Поэтому сервис умеет не только читать, но и предлагать:
 * распознаёт контакты из текстовых полей профиля CRM и подсказывает адреса,
 * на которые менеджер уже писал.
 */
class ClientContactService
{
    /**
     * Контакты партнёра, сгруппированные для карточки: сначала привязанные
     * к юрлицу, затем общие (company_id = NULL).
     *
     * @return Collection<int, ClientContact>
     */
    public function forCompany(int $userId, ?int $companyId): Collection
    {
        return ClientContact::query()
            ->where('user_id', $userId)
            ->when($companyId !== null, fn ($q) => $q->where(function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->orWhereNull('company_id');
            }))
            ->orderByDesc('is_primary')
            ->orderBy('role')
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Контакты роли, годные для доставки, у конкретного контрагента.
     *
     * Контакт без company_id принадлежит партнёру целиком и годится для любого
     * его юрлица — так «бухгалтер» заводится один раз, а не под каждое юрлицо.
     *
     * @return Collection<int, ClientContact>
     */
    public function deliverableByRole(int $userId, ?int $companyId, ClientContactRole|string $role): Collection
    {
        return ClientContact::query()
            ->where('user_id', $userId)
            ->role($role)
            ->deliverable()
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id');

                if ($companyId !== null) {
                    $q->orWhere('company_id', $companyId);
                }
            })
            ->orderByDesc('is_primary')
            ->get();
    }

    /**
     * Распознать контакты из свободного текста профиля CRM.
     *
     * Черновики создаются неактивными: цена ошибки регулярки — письмо о финансах
     * чужому человеку, поэтому распознанное подтверждает менеджер.
     *
     * Идемпотентно: повторный запуск не плодит записи с тем же адресом.
     *
     * @return array{created: int, skipped: int}
     */
    public function importFromProfile(User $user, ?int $createdByUserId = null): array
    {
        $profile = CrmClientProfile::query()->where('user_id', $user->id)->first();

        if ($profile === null) {
            return ['created' => 0, 'skipped' => 0];
        }

        $sources = [
            [ClientContactRole::OWNER, $profile->owner_name ?? null, $profile->owner_contact ?? null],
            [ClientContactRole::ACCOUNTANT, $profile->accountant_name ?? null, $profile->accountant_contact ?? null],
            [ClientContactRole::MANAGER, $profile->decision_maker_name ?? null, $profile->decision_maker_contact ?? null],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($sources as [$role, $name, $contact]) {
            $parsed = $this->parseContact($name, $contact);

            if ($parsed === null) {
                continue;
            }

            $exists = ClientContact::query()
                ->withTrashed()
                ->where('user_id', $user->id)
                ->where('email', $parsed['email'])
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            ClientContact::create([
                'user_id' => $user->id,
                'company_id' => null,
                'full_name' => $parsed['full_name'],
                'role' => $role,
                'email' => $parsed['email'],
                'phone' => $parsed['phone'],
                'is_active' => false,
                'source' => ClientContact::SOURCE_PROFILE_IMPORT,
                'notes' => 'Распознано из профиля CRM — проверьте и активируйте',
                'created_by_user_id' => $createdByUserId,
            ]);

            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Достать email, телефон и имя из строки вида «Иванов Пётр, +7 912 …, buh@x.ru».
     *
     * Без email контакт бесполезен для рассылки, поэтому строки без адреса
     * пропускаются целиком.
     *
     * @return array{full_name: string, email: string, phone: string|null}|null
     */
    private function parseContact(?string $name, ?string $contact): ?array
    {
        $haystack = trim(($name ?? '').' '.($contact ?? ''));

        if ($haystack === '') {
            return null;
        }

        if (! preg_match('/[\w.+-]+@[\w-]+\.[\w.-]+/u', $haystack, $emailMatch)) {
            return null;
        }

        $email = mb_strtolower(trim($emailMatch[0], ".,;: \t"));

        $phone = null;
        if (preg_match('/\+?\d[\d\s()\-]{9,}\d/u', $haystack, $phoneMatch)) {
            $phone = trim($phoneMatch[0]);
        }

        // Имя — то, что осталось от строки имени; если её нет, берём часть адреса
        // до собаки, чтобы карточка не была безымянной.
        $fullName = trim((string) $name);

        if ($fullName === '') {
            $fullName = ucfirst(explode('@', $email)[0]);
        }

        return [
            'full_name' => mb_substr($fullName, 0, 191),
            'email' => $email,
            'phone' => $phone !== null ? mb_substr($phone, 0, 50) : null,
        ];
    }
}
