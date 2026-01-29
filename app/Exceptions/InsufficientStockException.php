<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    protected array $items;

    public function __construct(string $message, array $items)
    {
        parent::__construct($message);
        $this->items = $items;
    }

    public function getItems(): array
    {
        return $this->items;
    }
}
