<?php

namespace App\Enums;

enum NotificationStatus: string
{
    case Scheduled = 'scheduled';
    case Queued = 'queued';
    case Processing = 'processing';
    case Retrying = 'retrying';
    case Sent = 'sent';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Scheduled, self::Queued, self::Retrying]);
    }
}
