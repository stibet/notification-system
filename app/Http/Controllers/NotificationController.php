<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Enums\NotificationStatus;
use App\Http\Requests\StoreNotificationRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class NotificationController extends Controller
{
    public function __construct(private NotificationService $service) {}

    public function store(StoreNotificationRequest $request)
    {
        $data = $request->validated();
        $data['idempotency_key'] = $request->header('Idempotency-Key', $data['idempotency_key'] ?? null);

        try {
            [$created, $existing] = $this->service->create([$data]);
        } catch (UniqueConstraintViolationException) {
            // same key sent twice at the same time
            $existing = Notification::where('idempotency_key', $data['idempotency_key'])->get();
            $created = collect();
        }

        if ($created->isEmpty()) {
            return response()->json(['data' => $existing->first()], 200);
        }

        return response()->json(['data' => $created->first()], 202);
    }

    public function index(Request $request)
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(NotificationStatus::class)],
            'channel' => ['nullable', Rule::enum(Channel::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return Notification::query()
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->when($request->channel, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($request->from, fn ($query, $from) => $query->where('created_at', '>=', Carbon::parse($from)))
            ->when($request->to, fn ($query, $to) => $query->where('created_at', '<=', $this->endOf($to)))
            ->latest()
            ->paginate($request->integer('per_page', 20));
    }

    public function show(Notification $notification)
    {
        return ['data' => $notification];
    }

    public function cancel(Notification $notification)
    {
        if (! $this->service->cancel($notification)) {
            return response()->json([
                'message' => "This notification can't be cancelled, its status is {$notification->status->value}.",
            ], 409);
        }

        return ['data' => $notification->refresh()];
    }

    private function endOf(string $date): Carbon
    {
        $parsed = Carbon::parse($date);

        return strlen($date) === 10 ? $parsed->endOfDay() : $parsed;
    }
}
