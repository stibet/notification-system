<?php

namespace App\Enums;

// also used as the queue name
enum Priority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
