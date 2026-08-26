<?php

use App\Http\Controllers\Api\MiniApp\AppointmentController;
use App\Http\Controllers\Api\MiniApp\EarlierRequestController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['max.initdata', 'throttle:60,1'])
    ->get('/miniapp/ping', function (Request $request) {
        $r = $request->attributes->get('max_init');

        return response()->json([
            'ok' => true,
            'user_id' => $r->userId,
            'start_param' => $r->startParam,
        ]);
    });

Route::prefix('miniapp')->middleware(['max.initdata', 'throttle:60,1'])->group(function () {
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::get('/appointments/history', [AppointmentController::class, 'history']);
    Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
    Route::put('/appointments/{appointment}/earlier-request', [EarlierRequestController::class, 'store']);
    Route::delete('/appointments/{appointment}/earlier-request', [EarlierRequestController::class, 'destroy']);
    Route::get('/profile', [AppointmentController::class, 'profile']);
});
