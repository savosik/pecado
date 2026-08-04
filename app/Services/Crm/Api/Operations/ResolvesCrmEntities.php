<?php

namespace App\Services\Crm\Api\Operations;

use App\Models\User;
use App\Services\Crm\Api\OperationInput;

/**
 * Резолв клиента для операций API.
 *
 * Всегда через `User::visibleInCrm($actor)`: набор клиентов задаёт актор, а не
 * аргумент вызова, поэтому «id менеджера» в запросе не расширяет видимость ни
 * в одной операции. Чужой клиент даёт 404, а не 403 — существование записи не
 * подтверждается, как и в веб-контроллерах.
 */
trait ResolvesCrmEntities
{
    protected function client(User $actor, OperationInput $input, string $key = 'client'): User
    {
        return User::query()
            ->visibleInCrm($actor)
            ->findOrFail((int) $input->int($key));
    }
}
