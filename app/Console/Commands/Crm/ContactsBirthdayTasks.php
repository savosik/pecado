<?php

namespace App\Console\Commands\Crm;

use App\Services\Contacts\BirthdayService;
use App\Services\Crm\CrmTaskService;
use Illuminate\Console\Command;

/**
 * Задачи «Поздравить» на завтрашние дни рождения контактов.
 *
 * Идемпотентна: повторный прогон не плодит задачи. Ставится персональному
 * менеджеру партнёра; если менеджера нет, задача не заводится — поручение
 * «никому» лежало бы мёртвым грузом.
 */
class ContactsBirthdayTasks extends Command
{
    protected $signature = 'contacts:birthday-tasks {--days=1 : За сколько дней предупреждать}';

    protected $description = 'Поставить задачи «Поздравить» на ближайшие дни рождения контактов';

    public function handle(BirthdayService $birthdays, CrmTaskService $tasks): int
    {
        $created = $birthdays->scheduleGreetings($tasks, (int) $this->option('days'));

        $this->info("Задач заведено: {$created}");

        return self::SUCCESS;
    }
}
