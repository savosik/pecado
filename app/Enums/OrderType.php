<?php

namespace App\Enums;

enum OrderType: string
{
    case STANDARD = 'standard';
    case IN_STOCK = 'in_stock';
    case PREORDER = 'preorder';
}
