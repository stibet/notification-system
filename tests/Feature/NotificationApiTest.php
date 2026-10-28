<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private array $sms = [
        'channel' => 'sms',
        'recipient' => '+905551234567',
        'content' => 'Your code is 1234',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_it_creates_a_notification_and_queues_it_by_priority(): void
    {
        $response = $this->postJson('/api/notifications', $this->sms + ['priority' => 'high']);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.priority', 'high');

        Queue::assertPushedOn('high', SendNotification::class);
    }

    public function test_priority_defaults_to_normal(): void
    {
        $this->postJson('/api/notifications', $this->sms)->assertJsonPath('data.priority', 'normal');

        Queue::assertPushedOn('normal', SendNotification::class);
    }

    public function test_email_and_push_are_accepted(): void
    {
        $this->postJson('/api/notifications', ['channel' => 'email', 'recipient' => 'ada@example.com', 'content' => 'Hi'])
            ->assertStatus(202);
        $this->postJson('/api/notifications', ['channel' => 'push', 'recipient' => 'device-token-123456', 'content' => 'Hi'])
            ->assertStatus(202);
    }

    public function test_it_validates_required_fields(): void
    {
        $this->postJson('/api/notifications', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel', 'recipient', 'content']);
    }

    public function test_it_validates_per_channel_rules(): void
    {
        $this->postJson('/api/notifications', ['channel' => 'sms', 'recipient' => '05551234567', 'content' => str_repeat('a', 161)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['recipient', 'content']);

        $this->postJson('/api/notifications', ['channel' => 'email', 'recipient' => 'not-an-email', 'content' => 'Hi'])
            ->assertJsonValidationErrors(['recipient']);

        $this->postJson('/api/notifications', ['channel' => 'push', 'recipient' => 'short', 'content' => str_repeat('a', 257)])
            ->assertJsonValidationErrors(['recipient', 'content']);

        $this->postJson('/api/notifications', $this->sms + ['priority' => 'urgent'])
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_wrong_types_are_a_validation_error(): void
    {
        $this->postJson('/api/notifications', ['channel' => ['sms'], 'recipient' => ['x'], 'content' => ['x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel', 'recipient', 'content']);

        $this->postJson('/api/batches', ['notifications' => [['channel' => ['sms'], 'recipient' => '+905551234567', 'content' => 'Hi']]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notifications.0.channel']);
    }

    public function test_sms_limit_counts_characters_not_bytes(): void
    {
        $this->postJson('/api/notifications', ['content' => str_repeat('ğ', 160)] + $this->sms)->assertStatus(202);
    }

    public function test_the_same_idempotency_key_returns_the_first_notification(): void
    {
        $first = $this->postJson('/api/notifications', $this->sms, ['Idempotency-Key' => 'order-42']);
        $second = $this->postJson('/api/notifications', $this->sms, ['Idempotency-Key' => 'order-42']);

        $first->assertStatus(202);
        $second->assertStatus(200);
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Notification::count());
        Queue::assertPushed(SendNotification::class, 1);
    }

    public function test_the_idempotency_key_can_be_sent_in_the_body(): void
    {
        $this->postJson('/api/notifications', $this->sms + ['idempotency_key' => 'abc'])->assertStatus(202);
        $this->postJson('/api/notifications', $this->sms + ['idempotency_key' => 'abc'])->assertStatus(200);

        $this->assertSame(1, Notification::count());
    }

    public function test_a_scheduled_notification_is_delayed(): void
    {
        $response = $this->postJson('/api/notifications', $this->sms + ['scheduled_at' => now()->addHour()->toIso8601String()]);

        $response->assertStatus(202)->assertJsonPath('data.status', 'scheduled');
        Queue::assertPushed(SendNotification::class, fn ($job) => $job->delay !== null);
    }

    public function test_scheduled_at_must_be_in_the_future(): void
    {
        $this->postJson('/api/notifications', $this->sms + ['scheduled_at' => '2020-01-01 10:00:00'])
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_it_shows_a_notification(): void
    {
        $notification = Notification::factory()->create();

        $this->getJson("/api/notifications/{$notification->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.status', 'queued');
    }

    public function test_unknown_notification_returns_404(): void
    {
        $this->getJson('/api/notifications/0195f7a4-0000-7000-8000-000000000000')->assertNotFound();
        $this->getJson('/api/notifications/not-a-uuid')->assertNotFound();
    }

    public function test_it_lists_with_filters_and_pagination(): void
    {
        Notification::factory()->count(3)->create(['status' => NotificationStatus::Sent]);
        Notification::factory()->create(['status' => NotificationStatus::Failed]);
        Notification::factory()->create(['channel' => 'email', 'recipient' => 'a@b.com', 'status' => NotificationStatus::Sent]);

        $this->getJson('/api/notifications?status=sent&channel=sms')->assertJsonPath('total', 3);
        $this->getJson('/api/notifications?status=failed')->assertJsonPath('total', 1);
        $this->getJson('/api/notifications?per_page=2')
            ->assertJsonPath('total', 5)
            ->assertJsonPath('last_page', 3)
            ->assertJsonCount(2, 'data');
    }

    public function test_it_filters_by_date_range(): void
    {
        $this->travelTo('2026-10-01 12:00:00');
        Notification::factory()->create();
        $this->travelTo('2026-10-15 12:00:00');
        Notification::factory()->create();

        $this->getJson('/api/notifications?from=2026-10-10&to=2026-10-15')->assertJsonPath('total', 1);
        $this->getJson('/api/notifications?to=2026-10-01')->assertJsonPath('total', 1);
    }

    public function test_a_waiting_notification_can_be_cancelled(): void
    {
        $notification = Notification::factory()->create();

        $this->postJson("/api/notifications/{$notification->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_sent_notification_cannot_be_cancelled(): void
    {
        $notification = Notification::factory()->create(['status' => NotificationStatus::Sent]);

        $this->postJson("/api/notifications/{$notification->id}/cancel")->assertStatus(409);
        $this->assertSame(NotificationStatus::Sent, $notification->refresh()->status);
    }

    public function test_the_notification_is_saved_when_the_queue_is_down(): void
    {
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Connection refused'));

        $this->postJson('/api/notifications', $this->sms)->assertStatus(202);

        $this->assertNull(Notification::first()->dispatched_at);
    }

    public function test_notifications_that_were_not_queued_are_queued_later(): void
    {
        $waiting = Notification::factory()->create();
        $queued = Notification::factory()->create(['dispatched_at' => now()]);
        $this->travel(2)->minutes();

        $this->artisan('notifications:dispatch')->expectsOutput('Queued 1 notifications.');

        Queue::assertPushed(SendNotification::class, 1);
        Queue::assertPushed(fn (SendNotification $job) => $job->notificationId === $waiting->id);
        $this->assertNotNull($waiting->refresh()->dispatched_at);
    }

    public function test_responses_have_a_correlation_id(): void
    {
        $response = $this->postJson('/api/notifications', $this->sms, ['X-Correlation-ID' => 'checkout-1']);

        $response->assertHeader('X-Correlation-ID', 'checkout-1')
            ->assertJsonPath('data.correlation_id', 'checkout-1');

        $this->assertNotEmpty($this->getJson('/api/notifications')->headers->get('X-Correlation-ID'));
    }
}
