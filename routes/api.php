<?php

use App\Http\Controllers\BatchController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MetricsController;
use App\Http\Controllers\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

Route::post('notifications', [NotificationController::class, 'store']);
Route::get('notifications', [NotificationController::class, 'index']);
Route::get('notifications/{notification}', [NotificationController::class, 'show'])->whereUuid('notification');
Route::post('notifications/{notification}/cancel', [NotificationController::class, 'cancel'])->whereUuid('notification');

Route::post('batches', [BatchController::class, 'store']);
Route::get('batches/{batchId}', [BatchController::class, 'show'])->whereUuid('batchId');

Route::get('metrics', MetricsController::class);
Route::get('health', HealthController::class);

// fake provider for local testing, "[fail]" in the content returns 500
Route::post('mock-provider', function (Request $request) {
    if (str_contains((string) $request->input('content'), '[fail]')) {
        return response()->json(['error' => 'simulated failure'], 500);
    }

    return response()->json([
        'messageId' => (string) Str::uuid(),
        'status' => 'accepted',
        'timestamp' => now()->toIso8601String(),
    ], 202);
});
