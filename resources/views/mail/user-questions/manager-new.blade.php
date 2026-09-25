@component('mail::message')
# Новый вопрос с сайта

Клиент задал вопрос менеджеру на сайте.

**От:** {{ $question->name ?: 'без имени' }} ({{ $question->email }})
@if ($question->user_id)
**Тип:** зарегистрированный пользователь
@else
**Тип:** гость
@endif
**Тема:** {{ $question->subject }}
@if ($hasAttachment)
**Прикреплён файл:** да
@endif

**Текст:**

> {!! nl2br(e(\Illuminate\Support\Str::limit($question->body, 800))) !!}

@component('mail::button', ['url' => $adminUrl, 'color' => 'primary'])
Открыть вопрос
@endcomponent

— Pecado.ru
@endcomponent
