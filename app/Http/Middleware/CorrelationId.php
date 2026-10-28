<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;

class CorrelationId
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->header('X-Correlation-ID');

        if (! $id || strlen($id) > 100) {
            $id = (string) Str::uuid();
        }

        // context is added to logs and passed to queued jobs
        Context::add('correlation_id', $id);

        $response = $next($request);
        $response->headers->set('X-Correlation-ID', $id);

        return $response;
    }
}
