<?php

use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('notifications:dispatch', function (NotificationService $service) {
    $count = 0;

    Notification::whereNull('dispatched_at')
        ->whereIn('status', ['scheduled', 'queued'])
        ->where('created_at', '<', now()->subMinute())
        ->chunkById(500, function ($notifications) use ($service, &$count) {
            $service->dispatch($notifications);
            $count += $notifications->count();
        });

    $this->info("Queued {$count} notifications.");
})->purpose('Queue the notifications that were saved but could not be queued');

Schedule::command('notifications:dispatch')->everyMinute()->withoutOverlapping();
