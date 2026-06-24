<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingStatusController extends Controller
{
    public function show(int $id): Response
    {
        $appointment = Appointment::with(['service', 'master'])
            ->findOrFail($id);

        return Inertia::render('booking/status', [
            'appointment' => [
                'id' => $appointment->id,
                'status' => $appointment->status,
                'start_time' => $appointment->start_time->toISOString(),
                'created_at' => $appointment->created_at->toISOString(),
                'service' => [
                    'id' => $appointment->service->id,
                    'title' => $appointment->service->title,
                    'price' => (float) $appointment->service->price,
                    'duration_minutes' => $appointment->service->duration_minutes,
                ],
                'master' => [
                    'id' => $appointment->master->id,
                    'name' => $appointment->master->name,
                    'phone' => $appointment->master->phone,
                    'specialty' => $appointment->master->specialty,
                    'soft_deposit' => $appointment->master->soft_deposit,
                    'deposit_timeout' => $appointment->master->deposit_timeout,
                    'deposit_percent' => $appointment->master->deposit_percent,
                ],
            ],
        ]);
    }
}
