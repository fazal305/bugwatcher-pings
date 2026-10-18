<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);
