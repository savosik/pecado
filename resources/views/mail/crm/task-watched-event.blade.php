@component('mail::message')
@if ($event === 'closed')
# Задача на контроле закрыта
@else
# Задача на контроле перенесена
@endif

**{{ $task->title }}**

**Исполнитель:** {{ $assigneeName }}
@if ($event === 'closed' && $outcomeLabel)
**Исход:** {{ $outcomeLabel }}
@endif
@if ($event === 'postponed')
**Новый срок:** {{ $dueLabel ?? 'не задан' }}
**Переносов всего:** {{ $task->postponed_count }}
@endif

@if ($detail)
**Комментарий:**

> {!! nl2br(e(\Illuminate\Support\Str::limit($detail, 800))) !!}
@endif

@component('mail::button', ['url' => $taskUrl, 'color' => 'primary'])
Открыть задачу
@endcomponent

— Pecado.ru
@endcomponent
