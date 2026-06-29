<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class ClientModeController extends Controller
{
    public function enable(): RedirectResponse
    {
        session(['is_client_mode' => true]);

        Log::info('Master switched to client mode', [
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('client.bookings');
    }

    public function disable(): RedirectResponse
    {
        session()->forget('is_client_mode');

        Log::info('Master switched back to admin mode', [
            'user_id' => auth()->id(),
        ]);

        return redirect()->route('admin.calendar');
    }
}
