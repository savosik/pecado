<?php

use App\Enums\UserKind;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Первичная разметка: у кого есть роль — тот сотрудник, а не клиент.
 *
 * Роль в проекте выдают только персоналу (super-admin, content-manager,
 * catalogist, sales-*, storekeeper, warehouse-head, buyer-manager) — клиенту
 * роль не назначают никогда, витрина работает без ролей. Поэтому признак
 * даёт разметку без ложных срабатываний.
 *
 * Технические учётки без ролей этим не ловятся — их помечают руками в
 * /admin/users. Автоматически записывать в «не клиенты» всех без erp_id
 * было бы неверно: так подписались бы и живые клиенты, зарегистрировавшиеся
 * на сайте раньше, чем их завели в 1С.
 *
 * Откат возвращает 'client' ровно тем же учёткам, а не всем подряд, чтобы
 * ручная разметка служебных аккаунтов не сгорела при rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', (new User)->getMorphClass());
            })
            ->update(['user_kind' => UserKind::STAFF->value]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('user_kind', UserKind::STAFF->value)
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', (new User)->getMorphClass());
            })
            ->update(['user_kind' => UserKind::CLIENT->value]);
    }
};
