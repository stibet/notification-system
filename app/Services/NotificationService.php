<?php

namespace App\Services;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class NotificationService
{
    // Returns [created, existing]. Items with an already used idempotency key are skipped.
    public function create(array $items, ?string $batchId = null): array
    {
        $keys = array_filter(array_column($items, 'idempotency_key'), fn ($key) => $key !== null);
        $existing = Notification::whereIn('idempotency_key', $keys)->get();
        $usedKeys = $existing->pluck('idempotency_key')->flip();

        $created = DB::transaction(function () use ($items, $batchId, $usedKeys) {
            $created = collect();

            foreach ($items as $item) {
                $key = $item['idempotency_key'] ?? null;

                if ($key !== null && $usedKeys->has($key)) {
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
        try {
            $this->dispatch($created);
        } catch (Throwable $e) {
            // they are saved, notifications:dispatch will queue them later
            Log::error('Could not queue notifications', ['error' => $e->getMessage()]);
        }

        return [$created, $existing];
    }

    // dispatched_at stays empty until the jobs are on the queue. If the queue is
    // down, notifications:dispatch (every minute) finds these rows and queues them.
    public function dispatch(Collection $notifications): void
    {
        foreach ($notifications as $notification) {
            SendNotification::dispatch($notification->id, $notification->channel->value)
                ->onQueue($notification->priority->value)
                ->delay($notification->scheduled_at);
        }

        Notification::whereIn('id', $notifications->pluck('id'))->update(['dispatched_at' => now()]);
    }

    public function cancel(Notification $notification): bool
    {
        $updated = Notification::whereKey($notification->id)
            ->whereIn('status', ['scheduled', 'queued', 'retrying'])
            ->update(['status' => NotificationStatus::Cancelled]);

        return $updated === 1;
    }
}
