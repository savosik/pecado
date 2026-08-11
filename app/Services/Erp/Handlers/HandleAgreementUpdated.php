<?php

namespace App\Services\Erp\Handlers;

/**
 * Соглашение с клиентом из 1С — обновление (v16.0.0).
 *
 * Логика та же, что у создания: payload полный, и `updated` на неизвестное
 * соглашение обязано его создать. Разведение по двум разным реализациям уже
 * однажды стоило нам потерянных платежей — повторять не будем.
 */
class HandleAgreementUpdated extends HandleAgreementCreated
{
    protected string $event = 'agreement.updated';
}
