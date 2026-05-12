@component('mail::message')
# Проверка здоровья не пройдена

**Окружение:** {{ $env }}<br>
**Время:** {{ now()->format('d.m.Y H:i:s T') }}

Одна или несколько проверок системы вернули проблему. Список ниже.

@foreach ($results as $result)
@component('mail::panel')
**{{ $result->check->getLabel() }}** — {{ $result->status->value === 'failed' ? 'не пройдена' : ($result->status->value === 'warning' ? 'предупреждение' : $result->status->value) }}

{{ $result->getNotificationMessage() }}
@endcomponent
@endforeach

Это автоматическое уведомление от Spatie Health. Если ошибки повторяются — проверьте логи и состояние контейнеров.
@endcomponent
