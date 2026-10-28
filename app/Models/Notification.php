<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Enums\Priority;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    use HasFactory, HasUuids;

    // milliseconds, for latency
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'batch_id',
        'channel',
        'priority',
        'recipient',
        'content',
        'status',
        'idempotency_key',
        'correlation_id',
        'attempts',
        'last_error',
        'provider_message_id',
        'latency_ms',
        'scheduled_at',
        'dispatched_at',
        'sent_at',
    ];

    // internal, see NotificationService::dispatch()
    protected $hidden = ['dispatched_at'];

    protected $casts = [
        'channel' => Channel::class,
        'priority' => Priority::class,
        'status' => NotificationStatus::class,
        'scheduled_at' => 'datetime',
        'dispatched_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
