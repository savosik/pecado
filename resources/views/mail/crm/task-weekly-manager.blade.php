@component('mail::message')
# Ваши задачи за неделю {{ $period }}

@if ($stats['closed_total'] === 0 && $stats['overdue_now'] === 0 && $stats['postpones'] === 0)
Все задачи недели закрыты, просрочек нет — отличная неделя.
@else
**Закрыто:** {{ $stats['closed_total'] }}@if ($stats['closed_total'] > 0) — успешно {{ $stats['closed_success'] }}, с проблемой {{ $stats['closed_problem'] }}@endif

@if ($stats['with_due'] > 0)
**В срок:** {{ $stats['on_time'] }} из {{ $stats['with_due'] }} задач со сроком
@endif
@if ($stats['postpones'] > 0)
**Переносов за неделю:** {{ $stats['postpones'] }}
@endif

@if (count($stats['problem_titles']) > 0)
**Закрыто с проблемой** — стоит обсудить до понедельника:
@foreach ($stats['problem_titles'] as $title)
- {{ $title }}
@endforeach
@endif

@if ($stats['overdue_now'] > 0)
**Висит просроченным: {{ $stats['overdue_now'] }}**
@foreach ($stats['overdue_titles'] as $title)
- {{ $title }}
@endforeach
@endif
@endif

@if ($stats['next_week_count'] > 0)
**На следующую неделю:** {{ $stats['next_week_count'] }} задач@if ($stats['next_week_minutes'] > 0) (~{{ round($stats['next_week_minutes'] / 60, 1) }} ч по оценкам)@endif
@endif

@component('mail::button', ['url' => $tasksUrl, 'color' => 'primary'])
Открыть задачи
@endcomponent

— Pecado.ru
@endcomponent
