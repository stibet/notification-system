<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBatchRequest;
use App\Models\Notification;
use App\Services\NotificationService;
use Illuminate\Support\Str;

class BatchController extends Controller
{
    public function __construct(private NotificationService $service) {}

    public function store(StoreBatchRequest $request)
    {
        $batchId = (string) Str::uuid();

        [$created, $existing] = $this->service->create($request->validated('notifications'), $batchId);

        // the same batch was sent again: return the original batch id
        if ($created->isEmpty()) {
            $batchIds = $existing->pluck('batch_id')->unique();
            $batchId = $batchIds->count() === 1 ? $batchIds->first() : null;
        }

        return response()->json([
            'data' => [
                'batch_id' => $batchId,
                'created' => $created->count(),
                'duplicates' => $existing->count(),
                'notifications' => $created->concat($existing)->map(fn ($n) => [
                    'id' => $n->id,
                    'recipient' => $n->recipient,
                    'status' => $n->status,
                ]),
            ],
        ], $created->isEmpty() ? 200 : 202);
    }

    public function show(string $batchId)
    {
        $notifications = Notification::where('batch_id', $batchId)
            ->oldest()
            ->get(['id', 'channel', 'recipient', 'status', 'attempts', 'last_error']);

        abort_if($notifications->isEmpty(), 404, 'Batch not found.');

        return [
            'data' => [
                'batch_id' => $batchId,
                'total' => $notifications->count(),
                'status_counts' => $notifications->countBy(fn ($n) => $n->status->value),
                'notifications' => $notifications,
            ],
        ];
    }
}
