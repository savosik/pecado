<?php

namespace App\Services\Erp\Handlers;

class HandleAgreementUpdated
{
    public function handle(array $payload): void
    {
        (new HandleAgreementCreated())->handle($payload);
    }
}
