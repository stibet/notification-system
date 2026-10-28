<?php

namespace Database\Factories;

use App\Enums\NotificationStatus;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'channel' => 'sms',
            'priority' => 'normal',
            'recipient' => '+90555'.fake()->numerify('#######'),
            'content' => fake()->sentence(),
            'status' => NotificationStatus::Queued,
        ];
    }
}
