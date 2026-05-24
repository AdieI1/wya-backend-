<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);
    Route::put('/user', [AuthController::class, 'updateProfile']);

    Route::get('/event-types', [EventController::class, 'types']);
    Route::get('/events/feed', [EventController::class, 'feed']);
    Route::get('/events/calendar', [EventController::class, 'calendar']);
    Route::get('/events/history', [EventController::class, 'history']);
    Route::get('/events', [EventController::class, 'index']);
    Route::post('/events/join', [EventController::class, 'join']);
    Route::get('/events/by-code/{code}', [EventController::class, 'lookupByCode']);
    Route::get('/events/{id}/participants', [EventController::class, 'participants']);
    Route::post('/events/{id}/sessions/{sessionId}/time-in', [EventController::class, 'timeIn']);
    Route::post('/events/{id}/sessions/{sessionId}/time-out', [EventController::class, 'timeOut']);
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::post('/events', [EventController::class, 'store']);
    Route::put('/events/{id}', [EventController::class, 'update']);
    Route::patch('/events/{id}/cancel', [EventController::class, 'cancel']);
});
