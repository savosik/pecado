<?php

use App\Enums\UserKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Менеджеры отдела продаж — сотрудники, даже если у них нет роли на сайте.
 *
 * Разметка по ролям их не поймала: в админку менеджеры не заходят, роль им никто
 * не выдавал. При этом в 1С они заведены и партнёрами тоже (у компании несколько
 * юрлиц, сотрудник фигурирует как контрагент), поэтому `partner.created` завёл им
 * учётку пользователя и проставил `personal_manager_id` — и менеджер оказался
 * в CRM среди собственных клиентов.
 *
 * Два признака, потому что связь между учёткой и карточкой менеджера
 * необязательная (`personal_managers.user_id` заполняют вручную в админке):
 *  1. учётка привязана к карточке менеджера — надёжный признак;
 *  2. email учётки совпадает с email карточки — тот же человек, привязку просто
 *     не проставили. Совпадение адреса случайным не бывает.
 *
 * Откат возвращает 'client' только тем, кого пометила сама миграция.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->matchedAccounts()->update(['user_kind' => UserKind::STAFF->value]);
    }

    public function down(): void
    {
        $this->matchedAccounts()
            ->where('user_kind', UserKind::STAFF->value)
            ->update(['user_kind' => UserKind::CLIENT->value]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function matchedAccounts()
    {
        return DB::table('users')->where(function ($query) {
            $query
                ->whereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('personal_managers')
                    ->whereColumn('personal_managers.user_id', 'users.id'))
                ->orWhereExists(fn ($sub) => $sub->select(DB::raw(1))
                    ->from('personal_managers')
                    ->whereColumn('personal_managers.email', 'users.email')
                    ->whereNotNull('personal_managers.email')
                    ->where('personal_managers.email', '!=', ''));
        });
    }
};
