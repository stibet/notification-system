<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

class HealthController extends Controller
{
    public function __invoke()
    {
        $checks = [
            'database' => $this->check(fn () => DB::select('select 1')),
            'queue' => $this->check(fn () => Queue::size()),
        ];

        $healthy = ! in_array('down', $checks);

        return response()->json([
            'status' => $healthy ? 'ok' : 'down',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    private function check(callable $callback): string
    {
        try {
            $callback();

            return 'ok';
        } catch (Throwable) {
            return 'down';
        }
    }
}
