@php
    /** @var \App\Services\Payments\PaymentOrder $order */
    $box = 'background:#f4f4f6;border-radius:8px;padding:11px 14px;margin:0 0 8px;font-size:14px;color:#222222;line-height:1.5;';
@endphp
<p style="font-size:17px;font-weight:700;color:#222222;margin:0 0 10px;">Платёжное поручение на {{ $order->amountFormatted() }} ₽</p>
<p style="font-size:15px;color:#333333;line-height:1.5;margin:0 0 14px;">
    {{ $order->scenarioLabel }}: плательщик «{{ $order->payer['legal_name'] ?: $order->payer['name'] }}», получатель «{{ $order->payee['legal_name'] ?: $order->payee['name'] }}».
    Во вложении — PDF с QR-кодом и файл для загрузки в клиент-банк (1CClientBankExchange): реквизиты и назначение уже заполнены.
</p>
<div style="{{ $box }}">
    <div><strong>Получатель:</strong> {{ $order->payee['legal_name'] ?: $order->payee['name'] }}, ИНН {{ $order->payee['tax_id'] ?: '—' }}@if($order->payee['tax_code']), КПП {{ $order->payee['tax_code'] }}@endif</div>
    <div><strong>Счёт:</strong> {{ $order->payee['account_number'] }} в {{ $order->payee['bank_name'] ?: '—' }}, БИК {{ $order->payee['bank_bik'] }}</div>
    <div><strong>Назначение:</strong> {{ $order->purpose }}</div>
</div>
@if ($order->documents !== [])
    @foreach ($order->documents as $document)
        <div style="font-size:14px;color:#333333;margin:2px 0 4px;">№ {{ $document['number'] }}@if($document['date']) от {{ $document['date'] }}@endif — {{ number_format($document['amount'], 2, ',', ' ') }} ₽@if($document['due']), срок {{ $document['due'] }}@endif</div>
    @endforeach
@endif
