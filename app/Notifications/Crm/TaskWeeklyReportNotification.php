<?php

namespace App\Notifications\Crm;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Пятничный отчёт по задачам: менеджеру — своя неделя, РОПу — сводка отдела.
 *
 * Гейт по фича-флагу стоит на вызывающей стороне (`crm:tasks-weekly-report`).
 */
class TaskWeeklyReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int,int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  array<string, mixed>  $stats  личная сводка (managerStats)
     * @param  list<array<string, mixed>>|null  $departmentRows  строки по менеджерам — только у РОПа
     * @param  string  $periodLabel  «11.08 — 18.08»
     */
    public function __construct(
        public array $stats,
        public ?array $departmentRows,
        public string $periodLabel,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $isHead = $this->departmentRows !== null;

        return (new MailMessage)
            ->subject(($isHead
                ? 'Задачи отдела за неделю '
                : 'Ваши задачи за неделю ').$this->periodLabel.' — Pecado.ru')
            ->markdown($isHead ? 'mail.crm.task-weekly-head' : 'mail.crm.task-weekly-manager', [
                'stats' => $this->stats,
                'rows' => $this->departmentRows,
                'period' => $this->periodLabel,
                'tasksUrl' => url('/crm/tasks'),
            ]);
    }
}
