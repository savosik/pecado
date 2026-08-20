@component('mail::message')
# {{ $title }}

{{ $body }}

Если оплата уже отправлена, это письмо можно не учитывать — данные обновятся
после того, как платёж отразится в учёте.

@if (!empty($url))
@component('mail::button', ['url' => $url, 'color' => 'primary'])
Посмотреть в личном кабинете
@endcomponent
@endif

С уважением,<br>
команда Pecado.ru

@if (!empty($unsubscribeUrl))
@component('mail::subcopy')
Вы получили это письмо, потому что этот адрес указан для уведомлений об оплатах в Pecado.ru.
[Отписаться от этих уведомлений]({{ $unsubscribeUrl }})
@endcomponent
@endif
@endcomponent
