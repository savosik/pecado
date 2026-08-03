@component('mail::message')
@if ($overdue)
# Задача просрочена
@else
# Завтра истекает срок задачи
@endif

**{{ $task->title }}**

**Поставил:** {{ $authorName }}
@if ($clientName)
**Клиент:** {{ $clientName }}
@endif
**Срок:** {{ $dueLabel }}

@if ($task->description)
**Описание:**

> {!! nl2br(e(\Illuminate\Support\Str::limit($task->description, 800))) !!}
@endif

@component('mail::button', ['url' => $taskUrl, 'color' => $overdue ? 'error' : 'primary'])
Открыть задачу
@endcomponent

— Pecado.ru
@endcomponent
