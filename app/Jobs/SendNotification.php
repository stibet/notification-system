<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendNotification implements ShouldQueue
{
    use Queueable;

    // released for rate limit / retries, attempts are counted on the notification
    public $tries = 0;

    public $maxExceptions = 3;

    public $timeout = 30;

    public function __construct(
        public string $notificationId,
        public string $channel,
    ) {}

    public function middleware(): array
    {
        return [new RateLimited('notifications')];
    }

    public function handle(): void
    {
        Context::add('notification_id', $this->notificationId);

        if (! $this->claim()) {
            return;
        }

        $notification = Notification::findOrFail($this->notificationId);

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'X-Correlation-ID' => $notification->correlation_id,
                    'Idempotency-Key' => $notification->id,
                ])
                ->post(config('notifications.provider_url'), [
                    'to' => $notification->recipient,
                    'channel' => $notification->channel->value,
                    'content' => $notification->content,
                ]);
        } catch (ConnectionException $e) {
            $this->retryLater($notification, 'Connection error: '.$e->getMessage());

            return;
        }

        if ($response->successful()) {
            $this->markAsSent($notification, $response->json('messageId'));
        } elseif ($response->clientError() && $response->status() !== 429) {
            // no point retrying a bad request
            $this->markAsFailed($notification, "Provider returned {$response->status()}");
        } else {
            $this->retryLater($notification, "Provider returned {$response->status()}");
        }
    }

    public function failed(?Throwable $exception): void
    {
        Notification::whereKey($this->notificationId)
            ->whereNotIn('status', ['sent', 'cancelled'])
            ->update(['status' => NotificationStatus::Failed, 'last_error' => $exception?->getMessage()]);
    }

    // Only one worker can move the row to "processing", so it is never sent twice.
    // Rows stuck in "processing" (worker crashed) are taken again after 2 minutes.
    private function claim(): bool
    {
        $updated = Notification::whereKey($this->notificationId)
            ->where(function ($query) {
                $query->whereIn('status', ['scheduled', 'queued', 'retrying'])
                    ->orWhere(fn ($q) => $q->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(2)));
            })
            ->update([
                'status' => NotificationStatus::Processing,
                'attempts' => DB::raw('attempts + 1'),
            ]);

        return $updated === 1;
    }

    private function markAsSent(Notification $notification, ?string $messageId): void
    {
        $startedAt = $notification->scheduled_at ?? $notification->created_at;

        $notification->update([
            'status' => NotificationStatus::Sent,
            'provider_message_id' => $messageId,
            'sent_at' => now(),
            'latency_ms' => (int) $startedAt->diffInMilliseconds(now()),
            'last_error' => null,
        ]);

        Log::info('Notification sent', ['attempt' => $notification->attempts]);
    }

    private function markAsFailed(Notification $notification, string $error): void
    {
        $notification->update(['status' => NotificationStatus::Failed, 'last_error' => $error]);

        Log::error('Notification failed', ['attempt' => $notification->attempts, 'error' => $error]);
    }

    private function retryLater(Notification $notification, string $error): void
    {
        $delays = config('notifications.retry_delays');

        if ($notification->attempts > count($delays)) {
            $this->markAsFailed($notification, "Gave up after {$notification->attempts} attempts. Last error: {$error}");

            return;
        }

        $delay = $delays[$notification->attempts - 1];

        $notification->update(['status' => NotificationStatus::Retrying, 'last_error' => $error]);

        Log::warning('Notification will be retried', [
            'attempt' => $notification->attempts,
            'retry_in_seconds' => $delay,
            'error' => $error,
        ]);

        $this->release($delay);
    }
}
