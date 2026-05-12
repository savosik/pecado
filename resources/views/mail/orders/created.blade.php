@component('mail::message')
# Заказ {{ $orderNumber }} принят

Здравствуйте, {{ $name }}!

Спасибо за заказ. Мы приняли его и передали в обработку. Менеджер свяжется с вами при необходимости уточнить детали.

**Текущий статус:** {{ $order->status->label() }}
@if ($order->type?->value === 'preorder')
<br>**Тип заказа:** предзаказ
@endif

@if ($order->items && $order->items->count() > 0)
@component('mail::table')
| Товар | Кол-во | Цена | Сумма |
|:------|:------:|-----:|------:|
@foreach ($order->items as $item)
| {{ $item->name }} | {{ (int) $item->quantity }} | {{ number_format((float) $item->price, 2, ',', ' ') }} {{ $order->currency_code }} | {{ number_format((float) $item->subtotal, 2, ',', ' ') }} {{ $order->currency_code }} |
@endforeach
@endcomponent
@endif

**Итого:** {{ number_format((float) $order->total_amount, 2, ',', ' ') }} {{ $order->currency_code }}

@if ($order->delivery_address)
**Адрес доставки:** {{ $order->delivery_address }}
@endif

@component('mail::button', ['url' => $orderUrl, 'color' => 'primary'])
Открыть заказ в кабинете
@endcomponent

С уважением,<br>
команда Pecado.ru
@endcomponent
