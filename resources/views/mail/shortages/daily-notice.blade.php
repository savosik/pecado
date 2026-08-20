@component('mail::message')
# Недоборы за {{ $dayLabel }}

@if ($onBehalfOf)
Вы замещаете менеджера {{ $onBehalfOf }} — ниже недоборы по его клиентам.
@endif

Сегодня 1С отменила **{{ $linesCount }}** {{ $linesCount === 1 ? 'позицию' : 'позиций' }}
({{ $quantity }} шт. на {{ number_format($amount, 0, ',', ' ') }} ₽)
в {{ $ordersCount }} {{ $ordersCount === 1 ? 'заказе' : 'заказах' }} ваших клиентов.
Причину 1С не передаёт — разнесите строки: склад снял при сборке или клиент отказался.

@component('mail::table')
| Заказ | Партнёр | Товар | Кол-во | Сумма |
|:------|:--------|:------|-------:|------:|
@foreach ($items as $item)
| {{ $item->order?->erp_number ?: $item->order?->number ?: '—' }} | {{ \Illuminate\Support\Str::limit($item->order?->user?->display_name ?? '—', 28) }} | {{ \Illuminate\Support\Str::limit($item->product?->name ?: $item->name, 42) }} | {{ $item->quantity }} | {{ number_format((float) $item->subtotal, 0, ',', ' ') }} ₽ |
@endforeach
@endcomponent

@component('mail::button', ['url' => $journalUrl])
Разнести недоборы
@endcomponent

Строка уходит из списка, как только вы поставите метку «Склад» или «Клиент».
Подсказка в журнале показывает, был ли по заказу расходный ордер — если был,
сборка шла, и отмена почти наверняка складская.

— Pecado.ru
@endcomponent
