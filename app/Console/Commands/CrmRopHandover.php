<?php

namespace App\Console\Commands;

use App\Enums\Crm\TaskStatus;
use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\CrmTask;
use App\Models\CrmTaskRecurrence;
use App\Models\PersonalManager;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Разовая передача дел РОП: Астапенко → Елисеев (август 2026).
 *
 * Ключевой ход — карточка менеджера НЕ пересоздаётся, а переименовывается:
 * клиенты (users.personal_manager_id), лиды (crm_leads.manager_id), планы
 * (crm_sales_plans.target_id) и промо-аудитории ссылаются на id карточки
 * и «переезжают» сами. У карточки Астапенко нет erp_uuid, так что 1С её
 * не перетрёт. Переносятся отдельно только сущности, привязанные к учётке:
 * открытые задачи и шаблоны повторяющихся задач. История (комментарии,
 * звонки, письма, выполненные задачи) не переписывается — это журнал.
 *
 * По умолчанию — сухой прогон с отчётом. Запись только с `--apply`.
 * Команда идемпотентна: повторный запуск ничего не ломает.
 *
 * После боевого запуска (вне команды): переименовать колонку менеджера
 * в Google-таблице «План 2026» (SalesSheetImporter матчит по имени карточки)
 * и переименовать партнёра в 1С, иначе partner.updated вернёт старый erp_name.
 */
class CrmRopHandover extends Command
{
    protected $signature = 'crm:rop-handover
        {--apply : Выполнить передачу (по умолчанию — только отчёт)}';

    protected $description = 'Передача дел РОП: переименовать учётку Елисеева, перевесить карточку/задачи Астапенко, заблокировать его учётку';

    private const NEW_ROP_EMAIL = 'paxa333@gmail.com';

    private const OLD_ROP_EMAIL = 'salesdir@andrey-company.ru';

    private const NEW_ROP_NAME = 'Елисеев Павел';

    private const MANAGER_CARD_EMAIL = 'sales@pecado.ru';

    public function handle(): int
    {
        $newRop = User::where('email', self::NEW_ROP_EMAIL)->first();
        $oldRop = User::where('email', self::OLD_ROP_EMAIL)->first();

        if (! $newRop || ! $oldRop) {
            $this->error(sprintf(
                'Учётки не найдены: %s%s',
                $newRop ? '' : self::NEW_ROP_EMAIL.' ',
                $oldRop ? '' : self::OLD_ROP_EMAIL,
            ));

            return self::FAILURE;
        }

        $card = PersonalManager::where('user_id', $oldRop->id)->first()
            ?? PersonalManager::where('user_id', $newRop->id)->first();

        if (! $card) {
            $this->error('Карточка менеджера РОП не найдена (personal_managers.user_id не указывает ни на старую, ни на новую учётку).');

            return self::FAILURE;
        }

        $openTasks = CrmTask::where('assignee_id', $oldRop->id)
            ->whereIn('status', TaskStatus::activeValues());
        $recurrences = CrmTaskRecurrence::where('assignee_id', $oldRop->id);

        $this->table(['Что', 'Значение'], [
            ['Новая учётка РОП', sprintf('#%d %s (%s)', $newRop->id, $newRop->name, $newRop->email)],
            ['Старая учётка РОП', sprintf('#%d %s (%s), статус %s', $oldRop->id, $oldRop->name, $oldRop->email, $oldRop->status->value)],
            ['Карточка менеджера', sprintf('#%d %s, email %s, клиентов: %d', $card->id, $card->name, $card->email ?? '—', $card->users()->count())],
            ['Открытых задач к переносу', (string) (clone $openTasks)->count()],
            ['Шаблонов задач к переносу', (string) (clone $recurrences)->count()],
            ['Токенов агента к отзыву', (string) $oldRop->hasMany(\App\Models\CrmAgentToken::class)->where('is_active', true)->count()],
        ]);

        if (! $this->option('apply')) {
            $this->info('Сухой прогон: ничего не изменено. Запустите с --apply для передачи.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($newRop, $oldRop, $card, $openTasks, $recurrences) {
            // 1. Новая учётка: имя, роль РОП, признак сотрудника.
            $newRop->forceFill([
                'name' => self::NEW_ROP_NAME,
                'erp_name' => self::NEW_ROP_NAME,
                'user_kind' => UserKind::STAFF->value,
            ])->save();
            $newRop->assignRole('sales-head');

            // 2. Карточка: имя, контакты, привязка к новой учётке.
            $card->forceFill([
                'name' => self::NEW_ROP_NAME,
                'email' => self::MANAGER_CARD_EMAIL,
                'user_id' => $newRop->id,
                'is_active' => true,
            ])->save();

            // 3. Активная работа: открытые задачи и шаблоны повторяющихся.
            $openTasks->update(['assignee_id' => $newRop->id]);
            $recurrences->update(['assignee_id' => $newRop->id]);

            // 4. Уволенный: блокировка, снятие ролей (fallback недоборов берёт
            // первого sales-head — уволенный не должен в него попадать),
            // отзыв токенов агента.
            $oldRop->syncRoles([]);
            $oldRop->forceFill(['status' => UserStatus::BLOCKED->value])->save();
            $oldRop->hasMany(\App\Models\CrmAgentToken::class)->update(['is_active' => false]);
        });

        $this->info(sprintf(
            'Готово: карточка #%d теперь «%s» (%s), учётка #%d — РОП, учётка #%d заблокирована.',
            $card->id, self::NEW_ROP_NAME, self::MANAGER_CARD_EMAIL, $newRop->id, $oldRop->id,
        ));
        $this->warn('Не забудьте: ящик sales@pecado.ru должен существовать; переименовать колонку в «План 2026»; переименовать партнёра в 1С.');

        return self::SUCCESS;
    }
}
