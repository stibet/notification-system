<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Jobs\SendNotification;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BatchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function items(int $count): array
    {
        return array_map(fn ($i) => [
            'channel' => 'sms',
            'recipient' => '+9055500'.str_pad($i, 5, '0', STR_PAD_LEFT),
            'content' => "Message {$i}",
        ], range(1, $count));
    }

    public function test_it_creates_a_batch(): void
    {
        $response = $this->postJson('/api/batches', ['notifications' => [
            ['channel' => 'sms', 'recipient' => '+905551234567', 'content' => 'Hi', 'priority' => 'high'],
            ['channel' => 'email', 'recipient' => 'ada@example.com', 'content' => 'Hi'],
            ['channel' => 'push', 'recipient' => 'device-token-123456', 'content' => 'Hi', 'priority' => 'low'],
        ]]);

        $response->assertStatus(202)->assertJsonPath('data.created', 3);
        $this->assertSame(3, Notification::where('batch_id', $response->json('data.batch_id'))->count());

        Queue::assertPushedOn('high', SendNotification::class);
        Queue::assertPushedOn('normal', SendNotification::class);
        Queue::assertPushedOn('low', SendNotification::class);
    }

    public function test_it_accepts_1000_notifications(): void
    {
        $this->postJson('/api/batches', ['notifications' => $this->items(1000)])
            ->assertStatus(202)
            ->assertJsonPath('data.created', 1000);

        Queue::assertPushed(SendNotification::class, 1000);
    }

    public function test_it_rejects_more_than_1000(): void
    {
        $this->postJson('/api/batches', ['notifications' => $this->items(1001)])
            ->assertJsonValidationErrors(['notifications']);

        $this->assertSame(0, Notification::count());
    }

    public function test_one_invalid_item_rejects_the_whole_batch(): void
    {
        $items = $this->items(3);
        $items[2]['recipient'] = 'wrong';

        $this->postJson('/api/batches', ['notifications' => $items])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['notifications.2.recipient']);

        $this->assertSame(0, Notification::count());
    }

    public function test_known_idempotency_keys_are_not_created_again(): void
    {
        $items = $this->items(2);
        $items[0]['idempotency_key'] = 'a';
        $items[1]['idempotency_key'] = 'b';

        $batchId = $this->postJson('/api/batches', ['notifications' => $items])
            ->assertJsonPath('data.created', 2)
            ->json('data.batch_id');

        $this->postJson('/api/batches', ['notifications' => $items])
            ->assertStatus(200)
            ->assertJsonPath('data.batch_id', $batchId)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.duplicates', 2)
            ->assertJsonCount(2, 'data.notifications');

        $this->assertSame(2, Notification::count());
        $this->getJson("/api/batches/{$batchId}")->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_zero_is_a_valid_idempotency_key(): void
    {
        $items = $this->items(1);
        $items[0]['idempotency_key'] = '0';

        $this->postJson('/api/batches', ['notifications' => $items])->assertStatus(202);
        $this->postJson('/api/batches', ['notifications' => $items])
            ->assertStatus(200)
            ->assertJsonPath('data.duplicates', 1);

        $this->assertSame(1, Notification::count());
    }

    public function test_idempotency_keys_must_be_unique_inside_a_batch(): void
    {
        $items = $this->items(2);
        $items[0]['idempotency_key'] = 'same';
        $items[1]['idempotency_key'] = 'same';

        $this->postJson('/api/batches', ['notifications' => $items])
            ->assertJsonValidationErrors(['notifications.1.idempotency_key']);
    }

    public function test_it_shows_the_status_of_a_batch(): void
    {
        $batchId = $this->postJson('/api/batches', ['notifications' => $this->items(3)])->json('data.batch_id');
        Notification::where('batch_id', $batchId)->first()->update(['status' => NotificationStatus::Sent]);

        $this->getJson("/api/batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.status_counts.sent', 1)
            ->assertJsonPath('data.status_counts.queued', 2)
            ->assertJsonCount(3, 'data.notifications');
    }

    public function test_unknown_batch_returns_404(): void
    {
        $this->getJson('/api/batches/0195f7a4-0000-7000-8000-000000000000')->assertNotFound();
    }
}
