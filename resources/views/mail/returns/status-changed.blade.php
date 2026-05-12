@component('mail::message')
# Возврат {{ $returnNumber }}: {{ $newStatus->label() }}

Здравствуйте, {{ $name }}!

{{ $message }}

@if ($previousStatus)
**Статус изменился:** {{ $previousStatus->label() }} → **{{ $newStatus->label() }}**
@else
**Текущий статус:** {{ $newStatus->label() }}
@endif

@if ($newStatus === \App\Enums\ReturnStatus::REJECTED && $productReturn->admin_comment)
@component('mail::panel')
**Комментарий менеджера:** {{ $productReturn->admin_comment }}
@endcomponent
@endif

@component('mail::button', ['url' => $returnUrl, 'color' => 'primary'])
Открыть заявку в кабинете
@endcomponent

С уважением,<br>
команда Pecado.ru
@endcomponent
