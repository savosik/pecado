@component('mail::message')
# Пароль изменён

Здравствуйте, {{ $name }}!

Сообщаем, что пароль для вашего аккаунта на Pecado.ru только что был изменён {{ $sourceLabel }}.

- Время: **{{ $changedAt }}**
@if ($ip)
- IP-адрес: **{{ $ip }}**
@endif

Если это были вы — никаких действий не требуется.

@component('mail::panel')
**Это были не вы?** Срочно свяжитесь с поддержкой и временно заблокируйте аккаунт.
@endcomponent

@component('mail::button', ['url' => 'mailto:' . $supportEmail, 'color' => 'primary'])
Написать в поддержку
@endcomponent

С уважением,<br>
команда Pecado.ru
@endcomponent
