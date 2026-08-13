<?php

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
