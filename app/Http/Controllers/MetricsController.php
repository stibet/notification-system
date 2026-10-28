<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class MetricsController extends Controller
{
    public function __invoke()
    {
        $counts = DB::table('notifications')
            ->selectRaw('channel, status, count(*) as total')
            ->groupBy('channel', 'status')
            ->get();

        $channels = [];

        foreach (Channel::cases() as $channel) {
            $byStatus = $counts->where('channel', $channel->value)->pluck('total', 'status');
            $sent = (int) ($byStatus['sent'] ?? 0);
            $failed = (int) ($byStatus['failed'] ?? 0);
            $finished = $sent + $failed;

            $channels[$channel->value] = [
                'by_status' => $byStatus,
                'success_rate' => $finished ? round($sent / $finished, 4) : null,
                'failure_rate' => $finished ? round($failed / $finished, 4) : null,
                'latency_ms' => $this->latency($channel),
            ];
        }

        return [
            'queue_depth' => [
                'high' => Queue::size('high'),
                'normal' => Queue::size('normal'),
                'low' => Queue::size('low'),
            ],
            'channels' => $channels,
        ];
    }

    // last 1000 sent notifications
    private function latency(Channel $channel): ?array
    {
        $values = DB::table('notifications')
            ->where('channel', $channel->value)
            ->whereNotNull('latency_ms')
            ->latest('sent_at')
            ->limit(1000)
            ->pluck('latency_ms')
            ->sort()
            ->values();

        if ($values->isEmpty()) {
            return null;
        }

        return [
            'avg' => (int) round($values->avg()),
            'p95' => $values[(int) ceil($values->count() * 0.95) - 1],
            'max' => $values->last(),
        ];
    }
}
