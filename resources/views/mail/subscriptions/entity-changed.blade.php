@php
    // Разбиваем готовый человекочитаемый summary на строки. Первую строку,
    // если она вводная (заканчивается двоеточием, напр. «Заказ по API принят
    // не в полном объёме:»), показываем подзаголовком, остальные — списком,
    // чтобы изменения не шли сплошным абзацем.
    $lines = array_values(array_filter(
        array_map('trim', preg_split('/\r\n|\r|\n/', (string) $body)),
        fn ($l) => $l !== ''
    ));
    $intro = (count($lines) > 1 && str_ends_with($lines[0], ':')) ? array_shift($lines) : null;
@endphp

@component('mail::message')
# {{ $sectionLabel }}: изменение

@if ($entityLabel)
**{{ $entityLabel }}**
@endif

@isset($intro)
{{ $intro }}
@endisset

@component('mail::panel')
@foreach ($lines as $line)
- {{ $line }}
@endforeach
@endcomponent

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
