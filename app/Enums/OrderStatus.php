<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case READY_TO_SHIP = 'ready_to_ship';
    case CLOSED = 'closed';
    case DELETED = 'deleted';
}
