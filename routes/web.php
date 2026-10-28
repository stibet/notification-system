<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/docs');

Route::view('docs', 'docs');
Route::get('docs/openapi.yaml', fn () => response()->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml']));
