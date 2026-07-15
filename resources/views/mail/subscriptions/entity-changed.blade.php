@component('mail::message')
# {{ $sectionLabel }}: изменение

@if ($entityLabel)
**{{ $entityLabel }}**
@endif

@foreach (preg_split('/\r\n|\r|\n/', $body) as $line)
@if (trim($line) !== '')
{{ $line }}
@endif
@endforeach

@if ($url)
@component('mail::button', ['url' => $url, 'color' => 'primary'])
Открыть в личном кабинете
@endcomponent
@endif

С уважением,<br>
команда Pecado.ru

@component('mail::subcopy')
Вы получили это письмо, потому что этот адрес подписан на изменения в разделе «{{ $sectionLabel }}» личного кабинета Pecado.ru.
[Отписаться от уведомлений]({{ $unsubscribeUrl }})
@endcomponent
@endcomponent
