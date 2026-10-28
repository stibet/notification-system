<?php

return [

    'provider_url' => env('PROVIDER_URL', 'http://localhost:8000/api/mock-provider'),

    // per channel
    'rate_limit_per_second' => (int) env('RATE_LIMIT_PER_SECOND', 100),

    'max_length' => [
        'sms' => 160,
        'email' => 10000,
        'push' => 256,
    ],

    // seconds to wait before each retry, 5 attempts in total
    'retry_delays' => [10, 30, 90, 270],

];
