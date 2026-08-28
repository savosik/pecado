@php
    /** @var \App\Services\Payments\PaymentOrder $order */
    $payer = $order->payer;
    $payee = $order->payee;
    $cell = 'border:1px solid #333;padding:4px 6px;vertical-align:top;font-size:10.5px;';
    $label = 'font-size:8px;color:#555;';
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Платёжное поручение № {{ $order->number }}</title>
    <style>
        @page { margin: 18mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #111; }
        table { border-collapse: collapse; width: 100%; }
        h1 { font-size: 15px; margin: 0 0 4px; }
        .muted { color: #555; font-size: 9px; }
        .amount { font-size: 13px; font-weight: bold; }
        .docs td { border-bottom: 1px solid #ddd; padding: 3px 4px; font-size: 10px; }
        .docs th { text-align: left; border-bottom: 1px solid #333; padding: 3px 4px; font-size: 9px; color: #555; }
    </style>
</head>
<body>
    <table style="margin-bottom:10px;">
        <tr>
            <td style="width:70%;vertical-align:top;">
                <h1>ПЛАТЁЖНОЕ ПОРУЧЕНИЕ № {{ $order->number }}</h1>
                <div class="muted">от {{ $order->date->format('d.m.Y') }} · {{ $order->scenarioLabel }} · подготовлено на Pecado.ru</div>
                @if ($order->contract)
                    <div style="margin-top:3px;font-size:10px;">Основание: {{ $order->contract['label'] }}</div>
                @endif
                <div class="muted" style="margin-top:4px;">
                    Заготовка для вашего клиент-банка: реквизиты получателя и назначение уже заполнены.
                    Номер и дату документа проставит ваша бухгалтерия.
                </div>
            </td>
            <td style="width:30%;text-align:right;vertical-align:top;">
                <img src="{{ $qr }}" alt="QR для оплаты" style="width:110px;height:110px;">
                <div class="muted">QR по ГОСТ Р 56042</div>
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td style="{{ $cell }}width:50%;">
                <div style="{{ $label }}">Сумма</div>
                <div class="amount">{{ $order->amountFormatted() }} ₽</div>
            </td>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">Вид платежа / очерёдность</div>
                <div>электронно · 5</div>
            </td>
        </tr>
    </table>

    <table style="margin-top:6px;">
        <tr>
            <td style="{{ $cell }}width:50%;">
                <div style="{{ $label }}">Плательщик</div>
                <div>{{ $payer['legal_name'] ?: $payer['name'] }}</div>
                <div class="muted">ИНН {{ $payer['tax_id'] ?: '—' }}@if($payer['tax_code']) · КПП {{ $payer['tax_code'] }}@endif</div>
            </td>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">Счёт плательщика</div>
                <div>{{ $payer['account_number'] ?: '—' }}</div>
            </td>
        </tr>
        <tr>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">Банк плательщика</div>
                <div>{{ $payer['bank_name'] ?: '—' }}</div>
            </td>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">БИК / корр. счёт</div>
                <div>{{ $payer['bank_bik'] ?: '—' }}@if($payer['correspondent_account']) · {{ $payer['correspondent_account'] }}@endif</div>
            </td>
        </tr>
        <tr>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">Банк получателя</div>
                <div>{{ $payee['bank_name'] ?: '—' }}</div>
            </td>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">БИК / корр. счёт</div>
                <div>{{ $payee['bank_bik'] }}@if($payee['correspondent_account']) · {{ $payee['correspondent_account'] }}@endif</div>
            </td>
        </tr>
        <tr>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">Получатель</div>
                <div><strong>{{ $payee['legal_name'] ?: $payee['name'] }}</strong></div>
                <div class="muted">ИНН {{ $payee['tax_id'] ?: '—' }}@if($payee['tax_code']) · КПП {{ $payee['tax_code'] }}@endif</div>
            </td>
            <td style="{{ $cell }}">
                <div style="{{ $label }}">Счёт получателя</div>
                <div><strong>{{ $payee['account_number'] }}</strong></div>
            </td>
        </tr>
        <tr>
            <td colspan="2" style="{{ $cell }}">
                <div style="{{ $label }}">Назначение платежа</div>
                <div>{{ $order->purpose }}</div>
            </td>
        </tr>
    </table>

    @if ($order->documents !== [])
        <div style="margin-top:12px;font-size:10px;color:#555;">Документы, по которым сформирована сумма</div>
        <table class="docs" style="margin-top:4px;">
            <tr>
                <th>Документ</th>
                <th>Дата</th>
                <th>Срок оплаты</th>
                <th style="text-align:right;">К оплате, ₽</th>
            </tr>
            @foreach ($order->documents as $document)
                <tr>
                    <td>№ {{ $document['number'] }}</td>
                    <td>{{ $document['date'] ?? '—' }}</td>
                    <td>{{ $document['due'] ?? '—' }}@if($document['overdue']) <span style="color:#a11;">просрочен</span>@endif</td>
                    <td style="text-align:right;">{{ number_format($document['amount'], 2, ',', ' ') }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="muted" style="margin-top:14px;">
        Файл для загрузки в клиент-банк (1CClientBankExchange) — в личном кабинете, раздел «Платёжное поручение».
        Вопросы по сумме — вашему менеджеру или в акте сверки.
    </div>
</body>
</html>
