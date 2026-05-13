@component('mail::message')
# Ваш вопрос принят

Здравствуйте, {{ $name }}!

Спасибо, что обратились в Pecado. Мы получили ваш вопрос и ответим в течение 1 рабочего дня.

**Тема:** {{ $question->subject }}

**Ваш вопрос:**

> {!! nl2br(e($question->body)) !!}

@if ($isAuthenticated && $cabinetUrl)
@component('mail::button', ['url' => $cabinetUrl, 'color' => 'primary'])
Открыть в личном кабинете
@endcomponent
@else
Ответ придёт на этот email-адрес.
@endif

С уважением,<br>
команда Pecado.ru
@endcomponent
