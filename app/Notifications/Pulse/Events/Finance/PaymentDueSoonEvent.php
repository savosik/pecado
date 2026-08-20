<?php

namespace App\Notifications\Pulse\Events\Finance;

use App\Notifications\Pulse\Events\AbstractNotificationEvent;
use App\Notifications\Pulse\Support\FieldSpec;

/**
 * Приближается срок оплаты по графику реализации.
 *
 * Кейс заказчика: «разным бухгалтерам разных контрагентов присылать,
 * что подходит срок оплаты, и присылать платёжку».
 */
class PaymentDueSoonEvent extends AbstractNotificationEvent
{
    public function key(): string
    {
        return 'finance.payment_due_soon';
    }

    public function label(): string
    {
        return 'Подходит срок оплаты';
    }

    public function description(): string
    {
        return 'По строке графика оплат приближается дата платежа';
    }

    public function fields(): array
    {
        return [
            'days_left' => new FieldSpec('days_left', 'Дней до срока', FieldSpec::TYPE_NUMBER),
            'amount' => new FieldSpec('amount', 'Сумма к оплате', FieldSpec::TYPE_MONEY),
            'due_date' => new FieldSpec('due_date', 'Дата платежа', FieldSpec::TYPE_DATE),
            'shipment_number' => new FieldSpec('shipment_number', 'Номер реализации', FieldSpec::TYPE_STRING),
            'organization_id' => new FieldSpec('organization_id', 'Наше юрлицо', FieldSpec::TYPE_NUMBER),
            'has_invoice_document' => new FieldSpec('has_invoice_document', 'Есть счёт на оплату', FieldSpec::TYPE_BOOL,
                hint: 'Счёт можно приложить к письму'),
        ];
    }

    protected function ownTags(array $data): array
    {
        return ['оплата:срок-подходит'];
    }

    public function defaultTemplate(): string
    {
        return 'mail.pulse.finance.payment-due-soon';
    }

    public function defaultSubject(): string
    {
        return 'Напоминание об оплате — Pecado.ru';
    }
}
