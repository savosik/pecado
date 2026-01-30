<?php

namespace App\Enums;

enum ReturnReason: string
{
    case DEFECTIVE = 'defective';
    case WRONG_ITEM = 'wrong_item';
    case CHANGED_MIND = 'changed_mind';
    case DAMAGED_IN_TRANSIT = 'damaged_in_transit';
    case WRONG_SIZE = 'wrong_size';
    case OTHER = 'other';
}
