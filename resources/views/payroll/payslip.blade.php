{{--
    Расчётный лист — короткий документ «сколько начислено».

    Ничего, кроме сумм и оснований: его печатают и подшивают. Объяснения,
    почему премия такая, живут во второй форме — «Расчёт с пояснениями».
--}}
@include('payroll._style')
@include('payroll._header')

@php
    $components = $calc['breakdown']['components'] ?? [];
    $kpi = $calc['kpi'];
    $inputs = $calc['inputs'];
@endphp

<table>
    <thead>
        <tr>
            <th style="width: 44%">Начисление</th>
            <th>Основание</th>
            <th class="right" style="width: 20%">Сумма, ₽</th>
        </tr>
    </thead>
    <tbody>
        {{-- Нулевые строки не печатаем: «Доп. доход — позиций нет, 0,00» только зашумляет лист. --}}
        @foreach (collect($components)->filter(fn ($c) => abs($c['amount']) >= 0.01 || in_array($c['key'], ['salary', 'kpi_bonus'], true)) as $component)
            <tr>
                <td>{{ $component['label'] }}</td>
                <td class="muted small">{{ $component['explanation'] ?? '' }}</td>
                <td class="right num {{ $component['amount'] < 0 ? 'neg' : '' }}">
                    {{ number_format($component['amount'], 2, ',', ' ') }}
                </td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="2">Итого к выплате</td>
            <td class="right">{{ number_format($calc['total'], 2, ',', ' ') }}</td>
        </tr>
    </tbody>
</table>

@if ($kpi)
    <h2>Как получилась премия</h2>
    <table>
        <tr>
            <td>Реализации за месяц</td>
            <td class="right num">{{ number_format($inputs['revenue'], 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>Вычет из выручки за задержки оплат</td>
            <td class="right num neg">−{{ number_format($kpi['penalty'] ?? 0, 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>Зачтено в план</td>
            <td class="right num">{{ number_format($kpi['adjusted'] ?? 0, 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>План месяца</td>
            <td class="right num">{{ number_format($inputs['plan'] ?? 0, 2, ',', ' ') }}</td>
        </tr>
        <tr>
            <td>Выполнение плана</td>
            <td class="right num">{{ $inputs['plan'] ? number_format(($kpi['adjusted'] ?? 0) / $inputs['plan'] * 100, 1, ',', ' ') : '—' }} %</td>
        </tr>
        <tr>
            <td>Множитель охвата клиентов ({{ $inputs['active_count'] }} из {{ $inputs['planned_count'] }})</td>
            <td class="right num">× {{ rtrim(rtrim(number_format($kpi['multiplier'] ?? 1, 2, ',', ' '), '0'), ',') }}</td>
        </tr>
        <tr>
            <td>Коэффициент премии</td>
            <td class="right num">{{ number_format($kpi['performance'] ?? 0, 4, ',', ' ') }}</td>
        </tr>
        <tr class="total">
            <td>Премия = базовая {{ number_format($kpi['base'] ?? 0, 0, ',', ' ') }} ₽ × коэффициент</td>
            <td class="right">{{ number_format($kpi['amount'], 2, ',', ' ') }}</td>
        </tr>
    </table>
    @if ($kpi['capped'])
        <p class="small">Премия упёрлась в потолок {{ number_format($kpi['max_amount'], 0, ',', ' ') }} ₽ — дальше рост не начисляется.</p>
    @endif
@endif

@if (count($inputs['extra_items']) > 0)
    <h2>Дополнительный доход</h2>
    <table>
        <thead><tr><th>Позиция</th><th class="right" style="width: 20%">Сумма, ₽</th></tr></thead>
        <tbody>
            @foreach ($inputs['extra_items'] as $item)
                <tr>
                    <td>{{ $item['label'] ?? 'Позиция' }}<span class="muted small">{{ $item['comment'] ? ' · '.$item['comment'] : '' }}</span></td>
                    <td class="right num">{{ number_format($item['amount'] ?? 0, 2, ',', ' ') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (count($calc['warnings']) > 0)
    <h2>Предупреждения</h2>
    @foreach ($calc['warnings'] as $warning)
        <div class="box">{{ $warning }}</div>
    @endforeach
@endif

<div class="foot">
    @if ($calc['status'] === 'draft')
        Черновик: месяц ещё не закрыт, суммы меняются с каждой отгрузкой и оплатой.
        Окончательный расчёт формируется после утверждения руководителем.
    @elseif ($calc['status'] === 'approved')
        Расчёт утверждён {{ $calc['approved_at'] ? \Carbon\Carbon::parse($calc['approved_at'])->format('d.m.Y') : '' }} и заморожен.
    @else
        Расчёт выплачен {{ $calc['paid_at'] ? \Carbon\Carbon::parse($calc['paid_at'])->format('d.m.Y') : '' }}.
    @endif
    Данные на {{ $calc['computed_at'] ? \Carbon\Carbon::parse($calc['computed_at'])->format('d.m.Y H:i') : '—' }}.
    Документ носит справочный характер и сформирован автоматически.
</div>
