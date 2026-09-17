@component('mail::message')
# Пока вас не было

@if ($onBehalfOf)
Вы замещаете менеджера {{ $onBehalfOf }} — ниже события по его клиентам.
@endif

Склад работает до 21:00 и по субботам, клиенты оформляют и забирают заказы сами.
Вот что произошло по вашим клиентам {{ $periodLabel }}.

@if ($notPicked > 0)
**Собрано и не забрано: {{ $notPicked }}.** Если заказ ждёт давно — позвоните клиенту.
@endif

@foreach ($sections as $title => $rows)
## {{ $title }}

@component('mail::table')
| Заказ | Клиент | Сумма | |
|:------|:-------|------:|:--|
@foreach ($rows as $row)
| {{ $row['number'] }} | {{ \Illuminate\Support\Str::limit($row['client'], 30) }} | {{ number_format($row['amount'], 0, ',', ' ') }} ₽ | {{ $row['note'] }} |
@endforeach
@endcomponent
@endforeach

Письмо справочное: делать ничего не нужно, если в нём нет раздела «Собраны и не забраны»
или «Требуют разбора со складом».

— Pecado.ru
@endcomponent
