<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingStatusController extends Controller
{
    public function show(int $id, Request $request): Response
    {
        $sessionId = session('current_appointment_id');

        if ($sessionId === null || (int) $sessionId !== $id) {
            abort(403);
        }

        $appointment = Appointment::with(['service', 'master'])
            ->find($id);

        if (! $appointment) {
            abort(404);
        }

        return Inertia::render('booking/status', [
            'appointment' => [
                'id' => $appointment->id,
                'status' => $appointment->status,
                'start_time' => $appointment->start_time->toISOString(),
                'created_at' => $appointment->created_at->toISOString(),
                'service' => [
                    'id' => $appointment->service->id,
                    'title' => $appointment->service->title,
                ],
                'master' => [
                    'id' => $appointment->master->id,
                    'name' => $appointment->master->name,
                    'specialty' => $appointment->master->specialty,
                ],
            ],
        ]);
    }
}
