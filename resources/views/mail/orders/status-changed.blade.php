@component('mail::message')
# Заказ {{ $orderNumber }}: {{ $newStatus->label() }}

Здравствуйте, {{ $name }}!

{{ $message }}

@if ($previousStatus)
**Статус изменился:** {{ $previousStatus->label() }} → **{{ $newStatus->label() }}**
@else
**Текущий статус:** {{ $newStatus->label() }}
@endif

@component('mail::button', ['url' => $orderUrl, 'color' => 'primary'])
Открыть заказ в кабинете
@endcomponent

С уважением,<br>
команда Pecado.ru
@endcomponent
