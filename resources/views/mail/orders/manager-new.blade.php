@component('mail::message')
# Новый заказ {{ $orderNumber }}

В систему поступил новый заказ. Краткая информация:

- **Клиент:** {{ $clientName }}
@if ($company)
- **Компания:** {{ $company }}
@endif
- **Тип:** {{ $order->type?->value === 'preorder' ? 'предзаказ' : 'заказ из наличия' }}
- **Статус:** {{ $order->status->label() }}
- **Сумма:** {{ number_format((float) $order->total_amount, 2, ',', ' ') }} {{ $order->currency_code }}
@if ($order->items && $order->items->count() > 0)
- **Позиций:** {{ $order->items->count() }}
@endif

@component('mail::button', ['url' => $adminUrl, 'color' => 'primary'])
Открыть заказ в админке
@endcomponent

Это автоматическое уведомление — отвечать на него не нужно.
@endcomponent
