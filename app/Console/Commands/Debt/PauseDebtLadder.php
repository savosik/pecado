<?php

namespace App\Console\Commands\Debt;

use App\Models\DebtPause;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Разблокировка до даты из консоли — «заплатка от рассылки» при выкатке.
 *
 * Для партнёров, с которыми договорённость есть до включения лестницы
 * (Гевея): пауза ставится раньше первого пересчёта, и ни письма, ни гейта,
 * ни задач по ним не будет. Дальше продлевает менеджер в CRM, как всем.
 */
class PauseDebtLadder extends Command
{
    protected $signature = 'debt:pause
        {user : id партнёра}
        {--days=30 : На сколько дней от сегодня (потолок РОП — 30)}
        {--reason= : Причина — что обещал клиент}
        {--by= : id сотрудника, от чьего имени; по умолчанию — персональный менеджер партнёра}
        {--company= : id контрагента; без него — партнёр целиком}
        {--if-never : Ставить только если у партнёра ещё не было ни одной разблокировки}';

    protected $description = 'Поставить партнёру разблокировку лестницы долга до даты';

    public function handle(): int
    {
        $user = User::query()->with('personalManager.user')->find((int) $this->argument('user'));

        if ($user === null) {
            $this->error('Партнёр не найден.');

            return self::FAILURE;
        }

        if ($this->option('if-never') && DebtPause::query()->where('user_id', $user->getKey())->exists()) {
            $this->info(sprintf('%s: разблокировка уже ставилась — пропущено.', $user->display_name));

            return self::SUCCESS;
        }

        $days = min(max(1, (int) $this->option('days')), (int) config('debt.pause_max_days_head', 30));
        $by = $this->option('by') !== null
            ? User::query()->find((int) $this->option('by'))
            : ($user->personalManager?->user
                ?? User::query()->whereHas('roles', fn ($roles) => $roles->where('name', 'super-admin'))->orderBy('id')->first());

        if ($by === null) {
            $this->error('Некому подписать разблокировку: укажите --by=<id сотрудника>.');

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?: 'Договорённость с менеджером: лестница долга не применяется до продления'));

        $pause = DebtPause::create([
            'user_id' => $user->getKey(),
            'company_id' => $this->option('company') !== null ? (int) $this->option('company') : null,
            'until' => now()->addDays($days)->toDateString(),
            'reason' => $reason,
            'created_by' => $by->getKey(),
        ]);

        $this->info(sprintf(
            '%s: разблокировка до %s (%s), поставил %s.',
            $user->display_name,
            $pause->until->format('d.m.Y'),
            $pause->company_id === null ? 'весь партнёр' : 'контрагент #'.$pause->company_id,
            $by->name,
        ));

        return self::SUCCESS;
    }
}
