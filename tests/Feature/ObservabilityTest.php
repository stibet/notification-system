<?php

namespace Tests\Feature;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_check(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'checks' => ['database' => 'ok', 'queue' => 'ok']]);
    }

    public function test_metrics(): void
    {
        Notification::factory()->count(3)->create(['status' => NotificationStatus::Sent, 'latency_ms' => 100]);
        Notification::factory()->create(['status' => NotificationStatus::Sent, 'latency_ms' => 500]);
        Notification::factory()->create(['status' => NotificationStatus::Failed]);

        $this->getJson('/api/metrics')
            ->assertOk()
            ->assertJsonStructure(['queue_depth' => ['high', 'normal', 'low'], 'channels' => ['sms', 'email', 'push']])
            ->assertJsonPath('channels.sms.by_status.sent', 4)
            ->assertJsonPath('channels.sms.success_rate', 0.8)
            ->assertJsonPath('channels.sms.failure_rate', 0.2)
            ->assertJsonPath('channels.sms.latency_ms.avg', 200)
            ->assertJsonPath('channels.sms.latency_ms.p95', 500)
            ->assertJsonPath('channels.email.success_rate', null);
    }

    public function test_mock_provider_answers_like_webhook_site(): void
    {
        $this->postJson('/api/mock-provider', ['to' => '+905551234567', 'channel' => 'sms', 'content' => 'Hi'])
            ->assertStatus(202)
            ->assertJsonStructure(['messageId', 'status', 'timestamp']);

        $this->postJson('/api/mock-provider', ['content' => 'Hi [fail]'])->assertStatus(500);
    }

    public function test_api_docs(): void
    {
        $this->get('/docs')->assertOk();
        $this->get('/docs/openapi.yaml')->assertOk();
    }
}
