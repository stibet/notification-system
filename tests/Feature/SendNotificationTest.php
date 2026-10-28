<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SendNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function runJob(Notification $notification): SendNotification
    {
        $job = (new SendNotification($notification->id, $notification->channel->value))->withFakeQueueInteractions();
        $job->handle();

        return $job;
    }

    private function providerAccepts(): void
    {
        Http::fake(['provider.test/*' => Http::response(['messageId' => 'msg-1', 'status' => 'accepted'], 202)]);
    }

    public function test_it_sends_to_the_provider(): void
    {
        $this->providerAccepts();
        $notification = Notification::factory()->create(['content' => 'Hello', 'correlation_id' => 'corr-1']);

        $this->runJob($notification)->assertNotReleased();

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame('msg-1', $notification->provider_message_id);
        $this->assertSame(1, $notification->attempts);
        $this->assertNotNull($notification->latency_ms);

        Http::assertSent(fn (Request $request) => $request->data() === [
            'to' => $notification->recipient,
            'channel' => 'sms',
            'content' => 'Hello',
        ] && $request->header('X-Correlation-ID')[0] === 'corr-1');
    }

    public function test_a_server_error_is_retried_later(): void
    {
        Http::fake(['provider.test/*' => Http::response('', 503)]);
        $notification = Notification::factory()->create();

        $this->runJob($notification)->assertReleased(10);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Retrying, $notification->status);
        $this->assertSame('Provider returned 503', $notification->last_error);

        $this->runJob($notification)->assertReleased(30);
    }

    public function test_a_connection_error_is_retried_later(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $notification = Notification::factory()->create();

        $this->runJob($notification)->assertReleased(10);
        $this->assertSame(NotificationStatus::Retrying, $notification->refresh()->status);
    }

    public function test_a_retry_can_succeed(): void
    {
        Http::fakeSequence('provider.test/*')
            ->push('', 500)
            ->push(['messageId' => 'msg-2'], 202);
        $notification = Notification::factory()->create();

        $this->runJob($notification);
        $this->runJob($notification);

        $notification->refresh();
        $this->assertSame(NotificationStatus::Sent, $notification->status);
        $this->assertSame(2, $notification->attempts);
    }

    public function test_it_gives_up_after_5_attempts(): void
    {
        Http::fake(['provider.test/*' => Http::response('', 500)]);
        $notification = Notification::factory()->create();

        foreach (range(1, 4) as $_) {
            $this->runJob($notification)->assertReleased();
        }
        $this->runJob($notification)->assertNotReleased();

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame(5, $notification->attempts);
        Http::assertSentCount(5);
    }

    public function test_a_client_error_fails_without_retrying(): void
    {
        Http::fake(['provider.test/*' => Http::response('', 400)]);
        $notification = Notification::factory()->create();

        $this->runJob($notification)->assertNotReleased();

        $this->assertSame(NotificationStatus::Failed, $notification->refresh()->status);
        Http::assertSentCount(1);
    }

    public function test_429_is_retried(): void
    {
        Http::fake(['provider.test/*' => Http::response('', 429)]);
        $notification = Notification::factory()->create();

        $this->runJob($notification)->assertReleased(10);
    }

    public function test_a_notification_is_never_sent_twice(): void
    {
        $this->providerAccepts();
        $notification = Notification::factory()->create();

        $this->runJob($notification);
        $this->runJob($notification);

        Http::assertSentCount(1);
    }

    public function test_cancelled_notifications_are_not_sent(): void
    {
        Http::fake();
        $notification = Notification::factory()->create(['status' => NotificationStatus::Cancelled]);

        $this->runJob($notification);

        Http::assertNothingSent();
    }

    public function test_a_notification_another_worker_is_sending_is_skipped(): void
    {
        Http::fake();
        $notification = Notification::factory()->create(['status' => NotificationStatus::Processing]);

        $this->runJob($notification);

        Http::assertNothingSent();
    }

    public function test_a_notification_stuck_in_processing_is_picked_up_again(): void
    {
        $this->providerAccepts();
        $notification = Notification::factory()->create(['status' => NotificationStatus::Processing]);
        $this->travel(3)->minutes();

        $this->runJob($notification);

        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);
    }

    public function test_scheduled_notifications_are_sent_when_the_job_runs(): void
    {
        $this->providerAccepts();
        $notification = Notification::factory()->create([
            'status' => NotificationStatus::Scheduled,
            'scheduled_at' => now(),
        ]);

        $this->runJob($notification);

        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);
    }

    public function test_the_rate_limit_is_per_channel(): void
    {
        config(['notifications.rate_limit_per_second' => 2]);
        $middleware = new RateLimited('notifications');
        $passed = 0;

        $run = function (string $channel) use ($middleware, &$passed) {
            $job = (new SendNotification('id', $channel))->withFakeQueueInteractions();
            $middleware->handle($job, function () use (&$passed) {
                $passed++;
            });

            return $job;
        };

        $run('sms');
        $run('sms');
        $run('sms')->assertReleased();
        $run('email');

        $this->assertSame(3, $passed);
    }

    public function test_the_failed_hook_marks_the_notification_as_failed(): void
    {
        $notification = Notification::factory()->create();

        (new SendNotification($notification->id, 'sms'))->failed(new \RuntimeException('database is down'));

        $notification->refresh();
        $this->assertSame(NotificationStatus::Failed, $notification->status);
        $this->assertSame('database is down', $notification->last_error);
    }

    public function test_full_flow_through_the_api(): void
    {
        $this->providerAccepts();

        $id = $this->postJson('/api/notifications', [
            'channel' => 'sms',
            'recipient' => '+905551234567',
            'content' => 'Hello',
        ])->json('data.id');

        $this->getJson("/api/notifications/{$id}")->assertJsonPath('data.status', 'sent');
    }
}
