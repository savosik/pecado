@component('mail::message')
# Вам поручена задача

**{{ $task->title }}**

**Поставил:** {{ $authorName }}
@if ($clientName)
**Клиент:** {{ $clientName }}
@endif
**Приоритет:** {{ $task->priority->label() }}
@if ($dueLabel)
**Срок:** {{ $dueLabel }}
@else
**Срок:** не задан
@endif

@if ($task->description)
**Описание:**

> {!! nl2br(e(\Illuminate\Support\Str::limit($task->description, 800))) !!}
@endif

@component('mail::button', ['url' => $taskUrl, 'color' => 'primary'])
Открыть задачу
@endcomponent

— Pecado.ru
@endcomponent
