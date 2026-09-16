<?php

namespace App\Services\Client\Api;

use App\Models\User;
use App\Services\Order\ReservePolicy;
use App\Support\Cabinet\CabinetFinance;
use App\Support\Cabinet\PaymentOrdersGate;

/**
 * Фича-гейт операции клиентского API.
 *
 * У клиента нет прав Spatie, зато есть разделы кабинета, закрытые флагами до
 * сверки с 1С (документы, финансы, договоры, режим резервов). Предикат каждого
 * гейта — тот же, что у middleware кабинета, и вызывается из одного места:
 * кабинет прячет раздел 404-м, API отвечает 403 с кодом. Разойтись им не с чем.
 */
enum FeatureGate: string
{
    case NONE = 'none';
    case DOCUMENTS = 'documents';
    case FINANCE = 'finance';
    case CONTRACTS = 'contracts';
    case RESERVE = 'reserve';
    case ORDER_CANCEL = 'order_cancel';
    case PAYMENT_ORDERS = 'payment_orders';

    /**
     * Открыт ли раздел для этого клиента прямо сейчас.
     */
    public function allows(?User $user): bool
    {
        return match ($this) {
            self::NONE => true,
            self::DOCUMENTS => (bool) config('documents.enabled'),
            self::FINANCE => CabinetFinance::enabledFor($user),
            self::CONTRACTS => (bool) config('contracts.cabinet_enabled'),
            self::RESERVE => $user !== null && app(ReservePolicy::class)->availableFor($user),
            self::ORDER_CANCEL => (bool) config('order_reserve.enabled'),
            self::PAYMENT_ORDERS => PaymentOrdersGate::availableFor($user),
        };
    }

    /**
     * Машиночитаемый код отказа для `errors[].code`.
     */
    public function code(): string
    {
        return match ($this) {
            self::NONE => 'allowed',
            self::DOCUMENTS => 'documents_disabled',
            self::FINANCE => 'finance_unavailable',
            self::CONTRACTS => 'contracts_disabled',
            self::RESERVE => 'reserve_unavailable',
            self::ORDER_CANCEL => 'order_cancel_unavailable',
            self::PAYMENT_ORDERS => 'payment_orders_unavailable',
        };
    }

    /**
     * Текст отказа для агента: что выключено и к кому идти.
     */
    public function reason(): string
    {
        return match ($this) {
            self::NONE => '',
            self::DOCUMENTS => 'Раздел «Документы» пока закрыт. Печатные формы запрашивайте у менеджера.',
            self::FINANCE => 'Раздел «Оплаты» вам пока недоступен. Баланс и сверку уточняйте у менеджера.',
            self::CONTRACTS => 'Раздел «Договоры» пока закрыт. Договор ведёт менеджер.',
            self::RESERVE => 'Режим «Заказы в резерве» вам недоступен.',
            self::ORDER_CANCEL => 'Отмена заказа из кабинета пока недоступна. Отмену оформляет менеджер.',
            self::PAYMENT_ORDERS => 'Платёжное поручение недоступно: раздел оплат закрыт, а ограничений по долгу нет.',
        };
    }
}
