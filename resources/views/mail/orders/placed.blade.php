@component('mail::message')
# Заказ принят

Здравствуйте, {{ $name }}!

Спасибо за заказ. Мы приняли его и передали в обработку. Менеджер свяжется с вами при необходимости уточнить детали.

Ваша покупка оформлена **несколькими документами** — так их обрабатывает склад. Это одна покупка, просто позиции едут разными путями.

@foreach ($orders as $order)
### {{ $order->erp_number ?: $order->number }} — {{ $order->type?->label() ?? 'Заказ' }}

@if ($order->type?->value === 'preorder')
Позиции под заказ: отгрузим, как только они поступят на склад.
@elseif ($order->type?->value === 'defect')
Товары с уценкой — отгружаются со склада некондиции.
@elseif ($order->type?->value === 'promo')
Промо-позиции по акции.
@elseif ($order->type?->value === 'promo_sample')
Рекламные образцы. В накладную не входят.
@else
Позиции в наличии.
@endif

@if ($order->items && $order->items->count() > 0)
@component('mail::table')
| Товар | Кол-во | Цена | Сумма |
|:------|:------:|-----:|------:|
@foreach ($order->items as $item)
| {{ $item->name }} | {{ (int) $item->quantity }} | {{ (float) $item->price > 0 ? number_format((float) $item->price, 2, ',', ' ') . ' ' . $order->currency_code : 'Бесплатно' }} | {{ (float) $item->subtotal > 0 ? number_format((float) $item->subtotal, 2, ',', ' ') . ' ' . $order->currency_code : 'Бесплатно' }} |
@endforeach
@endcomponent
@endif

**Сумма документа:** {{ (float) $order->total_amount > 0 ? number_format((float) $order->total_amount, 2, ',', ' ') . ' ' . $order->currency_code : 'Бесплатно' }}

@endforeach

---

**Итого по покупке:** {{ number_format((float) $total, 2, ',', ' ') }} {{ $currency }}

@if ($orders->first()?->delivery_address)
**Адрес доставки:** {{ $orders->first()->delivery_address }}
@endif

@component('mail::button', ['url' => $ordersUrl, 'color' => 'primary'])
Открыть заказы в кабинете
@endcomponent

С уважением,<br>
команда Pecado.ru
@endcomponent
