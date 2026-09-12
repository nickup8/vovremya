<?php

use App\Http\Controllers\Api\MiniApp\AppointmentController;
use App\Http\Controllers\Api\MiniApp\EarlierRequestController;
use App\Http\Controllers\Api\MiniApp\VkConsentController;
use App\Http\Controllers\Api\MiniApp\VkLinkController;
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

Route::prefix('miniapp')->middleware(['throttle:60,1'])->group(function () {
    // VK-only: client linking (unlinked clients, not behind miniapp.auth)
    Route::post('/vk-consent', VkConsentController::class)
        ->middleware(['vk.launch', 'throttle:10,1']);

    Route::post('/link', VkLinkController::class)
        ->middleware(['vk.launch', 'throttle:10,1']);

    // MAX-only: VK source/delivery not yet implemented
    Route::middleware('max.initdata')->group(function () {
        Route::put('/appointments/{appointment}/earlier-request', [EarlierRequestController::class, 'store']);
    });

    // Platform-neutral: MAX or VK
    Route::middleware('miniapp.auth')->group(function () {
        Route::get('/appointments', [AppointmentController::class, 'index']);
        Route::get('/appointments/history', [AppointmentController::class, 'history']);
        Route::post('/appointments/{appointment}/cancel', [AppointmentController::class, 'cancel']);
        Route::delete('/appointments/{appointment}/earlier-request', [EarlierRequestController::class, 'destroy']);
        Route::get('/profile', [AppointmentController::class, 'profile']);
    });
});
