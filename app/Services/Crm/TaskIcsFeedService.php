<?php

namespace App\Services\Crm;

use App\Enums\Crm\TaskStatus;
use App\Models\CrmCalendarToken;
use App\Models\CrmTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * ICS-фид задач для внешних календарей (Google, Яндекс).
 *
 * Генератор рукописный: формат — три десятка строк по RFC 5545, и тащить ради
 * них пакет незачем. Времена отдаются в UTC (суффикс Z) — это избавляет от
 * блока VTIMEZONE и одинаково корректно читается всеми календарями.
 */
class TaskIcsFeedService
{
    public function __construct(private readonly CrmTaskService $tasks) {}

    /**
     * Содержимое фида: открытые задачи со сроком, от месяца назад до года вперёд.
     */
    public function build(CrmCalendarToken $token): string
    {
        $owner = $token->user;

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Pecado//CRM Tasks//RU',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            $this->fold('X-WR-CALNAME:'.$this->escape($this->calendarName($token))),
            'X-WR-TIMEZONE:'.config('app.timezone'),
            // Подсказка календарям: фид обновляется, забирайте почаще.
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];

        foreach ($this->query($owner, $token->scope)->get() as $task) {
            array_push($lines, ...$this->event($task));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines)."\r\n";
    }

    /**
     * @return Builder<CrmTask>
     */
    private function query(User $owner, string $scope): Builder
    {
        $query = CrmTask::query()
            ->whereIn('status', TaskStatus::activeValues())
            ->whereNotNull('due_at')
            ->whereBetween('due_at', [now()->subMonth(), now()->addYear()])
            ->orderBy('due_at');

        if ($scope === CrmCalendarToken::SCOPE_DEPARTMENT && $owner->can('crm-department.view')) {
            return $query;
        }

        $ownerId = (int) $owner->getKey();

        // Личный фид: исполнение (ответственный или соисполнитель) + личный контроль.
        return $query->where(fn (Builder $inner) => $inner
            ->where('assignee_id', $ownerId)
            ->orWhereHas('coAssignees', fn (Builder $users) => $users->whereKey($ownerId))
            ->orWhereHas('watchers', fn (Builder $users) => $users->whereKey($ownerId)));
    }

    /**
     * @return list<string>
     */
    private function event(CrmTask $task): array
    {
        $due = $task->due_at;
        $isAllDay = $due->format('H:i:s') === '00:00:00';

        $summary = ($task->priority->value === 'high' ? '⚠ ' : '').$task->title;
        $url = url('/crm/tasks?task='.$task->getKey());

        $description = trim(($task->description ? $task->description."\n\n" : '').$url);

        $lines = [
            'BEGIN:VEVENT',
            // Стабильный UID: перенос срока обновляет событие, а не плодит копии.
            'UID:crm-task-'.$task->getKey().'@pecado.ru',
            // SEQUENCE растёт с каждым переносом — календарь понимает, что событие новее.
            'SEQUENCE:'.(int) $task->postponed_count,
            'DTSTAMP:'.$this->utc($task->updated_at ?? now()),
            'LAST-MODIFIED:'.$this->utc($task->updated_at ?? now()),
        ];

        if ($isAllDay) {
            // Полночь считаем «без времени» — событие на весь день.
            $lines[] = 'DTSTART;VALUE=DATE:'.$due->format('Ymd');
            $lines[] = 'DTEND;VALUE=DATE:'.$due->copy()->addDay()->format('Ymd');
        } else {
            $minutes = $task->estimate_minutes ?: 30;
            $lines[] = 'DTSTART:'.$this->utc($due);
            $lines[] = 'DTEND:'.$this->utc($due->copy()->addMinutes($minutes));
        }

        array_push(
            $lines,
            $this->fold('SUMMARY:'.$this->escape($summary)),
            $this->fold('DESCRIPTION:'.$this->escape($description)),
            $this->fold('URL:'.$this->escape($url)),
            'STATUS:CONFIRMED',
            'END:VEVENT',
        );

        return $lines;
    }

    private function calendarName(CrmCalendarToken $token): string
    {
        return $token->scope === CrmCalendarToken::SCOPE_DEPARTMENT
            ? 'Pecado CRM — задачи отдела'
            : 'Pecado CRM — мои задачи';
    }

    private function utc(\DateTimeInterface $moment): string
    {
        return \Illuminate\Support\Carbon::instance(\Illuminate\Support\Carbon::parse($moment))
            ->utc()
            ->format('Ymd\THis\Z');
    }

    /**
     * Экранирование текста по RFC 5545: запятые, точки с запятой, переводы строк.
     */
    private function escape(string $text): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\\;', '\\,', '\\n', '\\n'],
            $text,
        );
    }

    /**
     * Строки длиннее 75 октетов сворачиваются с продолжением через пробел.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 73) {
            return $line;
        }

        $chunks = [];

        while ($line !== '') {
            $take = 73;

            // Не резать многобайтовый символ посередине.
            while ($take > 1 && ! mb_check_encoding(substr($line, 0, $take), 'UTF-8')) {
                $take--;
            }

            $chunks[] = substr($line, 0, $take);
            $line = substr($line, $take);
        }

        return implode("\r\n ", $chunks);
    }
}
