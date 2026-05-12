@component('mail::message')
# Заявка на возврат {{ $returnNumber }} принята

Здравствуйте, {{ $name }}!

Мы получили вашу заявку на возврат. Менеджер рассмотрит её и согласует условия возврата с вами. Текущий статус заявки — **{{ $productReturn->status->label() }}**.

@if ($productReturn->items && $productReturn->items->count() > 0)
@component('mail::table')
| Товар | Кол-во | Сумма |
|:------|:------:|------:|
@foreach ($productReturn->items as $item)
| {{ $item->name ?? '—' }} | {{ (int) $item->quantity }} | {{ number_format((float) $item->subtotal, 2, ',', ' ') }} ₽ |
@endforeach
@endcomponent
@endif

**Итого к возврату:** {{ number_format((float) $productReturn->total_amount, 2, ',', ' ') }} ₽

@component('mail::button', ['url' => $returnUrl, 'color' => 'primary'])
Открыть заявку в кабинете
@endcomponent

С уважением,<br>
команда Pecado.ru
@endcomponent
