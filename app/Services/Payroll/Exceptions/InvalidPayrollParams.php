<?php

namespace App\Services\Payroll\Exceptions;

/**
 * Параметры компонента не прошли проверку схемой или доменными правилами.
 */
class InvalidPayrollParams extends \InvalidArgumentException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly string $componentKey, public readonly array $errors)
    {
        parent::__construct(sprintf(
            'Параметры компонента «%s» не прошли проверку: %s',
            $componentKey,
            implode('; ', $errors),
        ));
    }
}
