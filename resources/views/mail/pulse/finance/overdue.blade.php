@component('mail::message')
# {{ $title }}

{{ $body }}

Просим погасить задолженность или сообщить ожидаемую дату оплаты — так мы
сможем спланировать отгрузки и не задержать текущие заказы.

@if (!empty($url))
@component('mail::button', ['url' => $url, 'color' => 'primary'])
Посмотреть задолженность
@endcomponent
@endif

Если оплата уже отправлена, напишите вашему менеджеру — сверим платёж.

С уважением,<br>
команда Pecado.ru

@if (!empty($unsubscribeUrl))
@component('mail::subcopy')
Вы получили это письмо, потому что этот адрес указан для уведомлений об оплатах в Pecado.ru.
[Отписаться от этих уведомлений]({{ $unsubscribeUrl }})
@endcomponent
@endif
@endcomponent
