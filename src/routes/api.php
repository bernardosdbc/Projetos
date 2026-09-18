<?php

use App\Http\Controllers\DeadJobController;
use App\Http\Controllers\JobController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);
Route::post('/jobs', [JobController::class, 'store']);
Route::get('/jobs/{job}', [JobController::class, 'show']);
Route::get('/dead-jobs', [DeadJobController::class, 'index']);
Route::post('/dead-jobs/{job}/retry', [DeadJobController::class, 'retry']);