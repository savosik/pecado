@component('mail::message')
# {{ $title }}

{{ $body }}

@if (!empty($url))
@component('mail::button', ['url' => $url, 'color' => 'primary'])
Открыть раздел «Документы»
@endcomponent
@endif

{{-- Файл не вкладывается намеренно: печатные формы приватны и лежат
     на закрытом диске, а адрес получателя задаёт правило. Ссылка ведёт
     в кабинет, где доступ проверяется. --}}
Документ доступен для скачивания в личном кабинете.

С уважением,<br>
команда Pecado.ru

@if (!empty($unsubscribeUrl))
@component('mail::subcopy')
Вы получили это письмо, потому что этот адрес указан для уведомлений о документах в Pecado.ru.
[Отписаться от этих уведомлений]({{ $unsubscribeUrl }})
@endcomponent
@endif
@endcomponent
