@component('mail::message')
# Повторные недоборы за {{ $windowDays }} дней

Эти товары раз за разом отменяются при сборке — клиенты их заказывают,
а склада не хватает. Повторный недобор лечится запасом, а не заменой.

@component('mail::table')
| Товар | Отмен | Потеряно, ₽ | Остаток | Ожидается |
|:------|------:|------------:|--------:|----------:|
@foreach ($rows as $row)
| {{ \Illuminate\Support\Str::limit($row['name'], 60) }} | {{ $row['shortages'] }} | {{ number_format($row['lost_amount'], 0, ',', ' ') }} | {{ $row['stock'] }} | {{ $row['incoming'] }} |
@endforeach
@endcomponent

«Ожидается» — остаток на предзаказных складах региона по умолчанию.

С уважением,<br>
{{ config('app.name') }}
@endcomponent
