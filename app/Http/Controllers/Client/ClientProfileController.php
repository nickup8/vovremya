<?php

namespace App\Http\Controllers\Client;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientProfileController extends Controller
{
    public function index(): Response
    {
        $client = Auth::guard('client')->user();

        $master = $client->master;

        $stats = Appointment::where('client_id', $client->id)
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->select(
                DB::raw('COUNT(*) as total_bookings'),
                DB::raw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed_bookings'),
                DB::raw('(SELECT COALESCE(SUM(s.price), 0) FROM appointments a2 JOIN services s ON a2.service_id = s.id WHERE a2.client_id = ? AND a2.status = ?) as ltv'),
            )
            ->addBinding([$AppointmentStatus = AppointmentStatus::Paid->value, $client->id, $AppointmentStatus], 'select')
            ->first();

        $nextAppointment = Appointment::where('client_id', $client->id)
            ->whereIn('status', [AppointmentStatus::Booked, AppointmentStatus::PendingPayment, AppointmentStatus::Prepaid])
            ->where('start_time', '>=', now())
            ->with('service')
            ->orderBy('start_time')
            ->first();

        $nextAppointmentData = $nextAppointment ? [
            'id' => $nextAppointment->id,
            'service' => $nextAppointment->service?->title ?? 'Услуга',
            'date' => $nextAppointment->start_time->format('d.m.Y'),
            'time' => $nextAppointment->start_time->format('H:i'),
            'price' => (float) ($nextAppointment->service?->price ?? 0),
            'master_name' => $master->name,
        ] : null;

        return Inertia::render('client/profile', [
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
            ],
            'master' => [
                'name' => $master->name,
                'specialty' => $master->specialty,
                'address' => $master->address,
                'master_slug' => $master->master_slug,
            ],
            'stats' => [
                'total_bookings' => (int) $stats->total_bookings,
                'completed_bookings' => (int) $stats->completed_bookings,
                'ltv' => (float) $stats->ltv,
            ],
            'nextAppointment' => $nextAppointmentData,
        ]);
    }
}
