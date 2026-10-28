<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    // Returns [created, existing]. Items with an already used idempotency key are skipped.
    public function create(array $items, ?string $batchId = null): array
    {
        $keys = array_filter(array_column($items, 'idempotency_key'));
        $existing = Notification::whereIn('idempotency_key', $keys)->get();
        $usedKeys = $existing->pluck('idempotency_key')->flip();

        $created = DB::transaction(function () use ($items, $batchId, $usedKeys) {
            $created = collect();

            foreach ($items as $item) {
                $key = $item['idempotency_key'] ?? null;

                if ($key && $usedKeys->has($key)) {
                    continue;
                }

                $scheduledAt = isset($item['scheduled_at']) ? Carbon::parse($item['scheduled_at']) : null;

                $created->push(Notification::create([
                    'batch_id' => $batchId,
                    'channel' => $item['channel'],
                    'priority' => $item['priority'] ?? 'normal',
                    'recipient' => $item['recipient'],
                    'content' => $item['content'],
                    'status' => $scheduledAt ? NotificationStatus::Scheduled : NotificationStatus::Queued,
                    'idempotency_key' => $key,
                    'correlation_id' => Context::get('correlation_id'),
                    'scheduled_at' => $scheduledAt,
                ]));
            }

            return $created;
        });

        // dispatch after commit
        foreach ($created as $notification) {
            SendNotification::dispatch($notification->id, $notification->channel->value)
                ->onQueue($notification->priority->value)
                ->delay($notification->scheduled_at);
        }

        return [$created, $existing];
    }

    public function cancel(Notification $notification): bool
    {
        $updated = Notification::whereKey($notification->id)
            ->whereIn('status', ['scheduled', 'queued', 'retrying'])
            ->update(['status' => NotificationStatus::Cancelled]);

        return $updated === 1;
    }
}
