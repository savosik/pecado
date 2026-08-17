@component('mail::message')
# Задачи отдела за неделю {{ $period }}

@component('mail::table')
| Менеджер | Закрыто | Успешно / с проблемой | В срок | Переносы | Просрочено сейчас | След. неделя |
|:---------|--------:|:---------------------:|-------:|---------:|------------------:|-------------:|
@foreach ($rows as $row)
| {{ $row['name'] }} | {{ $row['stats']['closed_total'] }} | {{ $row['stats']['closed_success'] }} / {{ $row['stats']['closed_problem'] }} | {{ $row['stats']['with_due'] > 0 ? round($row['stats']['on_time'] / $row['stats']['with_due'] * 100).'%' : '—' }} | {{ $row['stats']['postpones'] }} | {{ $row['stats']['overdue_now'] }} | {{ $row['stats']['next_week_count'] }}@if ($row['stats']['next_week_minutes'] > 0) (~{{ round($row['stats']['next_week_minutes'] / 60, 1) }} ч)@endif |
@endforeach
@endcomponent

@php
    $problems = collect($rows)->flatMap(fn ($row) => collect($row['stats']['problem_titles'])
        ->map(fn ($title) => $row['name'].' — '.$title));
    $stale = collect($rows)->flatMap(fn ($row) => collect($row['stats']['overdue_titles'])
        ->map(fn ($title) => $row['name'].' — '.$title));
@endphp

@if ($problems->isNotEmpty())
**Закрытия с проблемой за неделю:**
@foreach ($problems->take(15) as $line)
- {{ $line }}
@endforeach
@endif

@if ($stale->isNotEmpty())
**Застарелые просрочки:**
@foreach ($stale->take(15) as $line)
- {{ $line }}
@endforeach
@endif

@component('mail::button', ['url' => $tasksUrl, 'color' => 'primary'])
Открыть задачи отдела
@endcomponent

— Pecado.ru
@endcomponent
