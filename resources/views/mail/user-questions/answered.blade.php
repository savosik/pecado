@component('mail::message')
# Ответ на ваш вопрос

Здравствуйте, {{ $name }}!

Мы ответили на ваш вопрос «{{ $question->subject }}».

@if ($isAuthenticated && $cabinetUrl)
**Ваш вопрос:**

> {!! nl2br(e($question->body)) !!}

@component('mail::button', ['url' => $cabinetUrl, 'color' => 'primary'])
Посмотреть ответ в кабинете
@endcomponent
@else
**Ваш вопрос:**

> {!! nl2br(e($question->body)) !!}

**Наш ответ:**

{!! nl2br(e($question->answer)) !!}
@endif

Если у вас остались вопросы — задайте их через форму на странице FAQ.

С уважением,<br>
команда Pecado.ru
@endcomponent
